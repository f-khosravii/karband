<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Policies;

/**
 * Pinned per-request SERVICE settings; never fetch mutable catalog rules for old requests.
 * Workflow alone defines valid source/target states and guards; this policy configures
 * the request-level cancellation/correction semantics around those transitions.
 */
final readonly class RequestPolicy
{
    /** @param list<string> $submitActions
     *  @param list<string> $submittedStates
     *  @param list<string> $terminalStates
     *  @param list<string> $cancellableStates
     *  @param list<string> $correctionStates
     *  @param array<string,string> $cancelActionsByState
     *  @param array<string,string> $recipientFieldMap Maps profile keys to form keys.
     */
    public function __construct(
        public string $draftState,
        public array $submitActions,
        public array $submittedStates,
        public array $terminalStates,
        public string $cancelledState,
        public array $cancellableStates,
        public array $correctionStates,
        public string $correctionMode,
        public string $correctionAction,
        public string $resubmitAction,
        public string $cancelDraftAction,
        public array $cancelActionsByState,
        public array $recipientFieldMap = [],
        public ?string $recipientIdentityField = null,
    ) {
        if (!in_array($this->correctionMode, ['full', 'limited'], true)) {
            throw new \InvalidArgumentException('Correction mode must be full or limited.');
        }
        foreach ([$this->draftState, $this->cancelledState, $this->correctionAction, $this->resubmitAction,
            $this->cancelDraftAction] as $action) {
            self::identifier($action);
        }
        foreach ([$this->submitActions, $this->submittedStates, $this->terminalStates,
            $this->cancellableStates, $this->correctionStates] as $list) {
            if (!array_is_list($list) || count($list) !== count(array_unique($list))) {
                throw new \InvalidArgumentException('Request policy lists must be unique lists.');
            }
            foreach ($list as $item) { self::identifier($item); }
        }
        if ($this->submitActions === [] || $this->submittedStates === [] || $this->terminalStates === []) {
            throw new \InvalidArgumentException('Submit actions, submitted and terminal states are mandatory.');
        }
        if (in_array($this->draftState, $this->terminalStates, true)) {
            throw new \InvalidArgumentException('Draft cannot be terminal.');
        }
        if (!in_array($this->cancelledState, $this->terminalStates, true) ||
            array_intersect($this->cancellableStates, $this->terminalStates) !== []) {
            throw new \InvalidArgumentException('Cancellation must end in its own terminal state, from non-terminal states only.');
        }
        foreach ($this->cancelActionsByState as $state => $action) {
            self::identifier($state);
            self::identifier($action);
            if (!in_array($state, $this->cancellableStates, true)) {
                throw new \InvalidArgumentException('Cancellation action references a non-cancellable state.');
            }
        }
        foreach ($this->recipientFieldMap as $profileKey => $formKey) {
            if (!is_string($profileKey) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $profileKey)) {
                throw new \InvalidArgumentException('Invalid profile field mapping.');
            }
            if (!is_string($formKey) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $formKey)) {
                throw new \InvalidArgumentException('Invalid form field mapping.');
            }
        }
        if ($this->recipientIdentityField !== null) {
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $this->recipientIdentityField)
                || in_array($this->recipientIdentityField, $this->recipientFieldMap, true)) {
                throw new \InvalidArgumentException('Invalid or duplicated identity form field.');
            }
        }
        if (count(array_unique(array_values($this->recipientFieldMap))) !== count($this->recipientFieldMap)) {
            throw new \InvalidArgumentException('Two profile fields cannot overwrite one form field.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = ['draft_state','submit_actions','submitted_states','terminal_states','cancelled_state','cancellable_states',
            'correction_states','correction_mode','correction_action','resubmit_action','cancel_draft_action',
            'cancel_actions_by_state'];
        if (array_diff($required, array_keys($data)) !== [] ||
            array_diff(array_keys($data), [...$required, 'recipient_field_map', 'recipient_identity_field']) !== []) {
            throw new \InvalidArgumentException('Missing or unexpected request policy fields.');
        }
        foreach ($required as $key) {
            $isArray = in_array($key, ['submit_actions','submitted_states','terminal_states','cancellable_states',
                'correction_states','cancel_actions_by_state'], true);
            if ($isArray ? !is_array($data[$key]) : !is_string($data[$key])) {
                throw new \InvalidArgumentException('Invalid request policy field: '.$key);
            }
        }
        if (!is_array($data['recipient_field_map'] ?? []) || (isset($data['recipient_identity_field']) && !is_string($data['recipient_identity_field']))) {
            throw new \InvalidArgumentException('Invalid recipient_field_map.');
        }
        return new self($data['draft_state'], $data['submit_actions'], $data['submitted_states'],
            $data['terminal_states'], $data['cancelled_state'], $data['cancellable_states'], $data['correction_states'],
            $data['correction_mode'], $data['correction_action'], $data['resubmit_action'],
            $data['cancel_draft_action'], $data['cancel_actions_by_state'], $data['recipient_field_map'] ?? [],
            $data['recipient_identity_field'] ?? null);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['draft_state'=>$this->draftState,'submit_actions'=>$this->submitActions,
            'submitted_states'=>$this->submittedStates,'terminal_states'=>$this->terminalStates,
            'cancelled_state'=>$this->cancelledState,'cancellable_states'=>$this->cancellableStates,'correction_states'=>$this->correctionStates,
            'correction_mode'=>$this->correctionMode,'correction_action'=>$this->correctionAction,
            'resubmit_action'=>$this->resubmitAction,'cancel_draft_action'=>$this->cancelDraftAction,
            'cancel_actions_by_state'=>$this->cancelActionsByState,'recipient_field_map'=>$this->recipientFieldMap,
            ...($this->recipientIdentityField === null ? [] : ['recipient_identity_field'=>$this->recipientIdentityField])];
    }

    public function cancellationAction(string $currentState): string
    {
        return $this->cancelActionsByState[$currentState]
            ?? throw new \DomainException('Cancellation is unavailable in the current workflow state.');
    }

    public function mayRequestCancellation(string $state): bool
    {
        return in_array($state, $this->cancellableStates, true) && $state !== $this->draftState;
    }

    private static function identifier(string $value): void
    {
        if (strlen($value) > 120 || !preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $value)) {
            throw new \InvalidArgumentException('Invalid workflow action or state identifier.');
        }
    }
}
