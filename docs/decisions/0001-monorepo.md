# ADR-0001: Shared Package Monorepo

Status: Accepted

## Context

INTL operates multiple independent electronic service
applications with potentially reusable capabilities.

## Decision

Reusable packages will initially be developed inside
a single Monorepo.

Each package will have its own Composer configuration,
namespace and automated tests.

Production applications will remain independent.

## Consequences

- Centralized development of shared packages.
- Easier integration testing.
- Explicit dependency management.
- Independent package versioning will be required.