# Project

Laravel 11+/12+/13+, PHP 8.2+, MySQL/SQLite

## Architecture
- Models, Services
- See src/ for the full structure

## Build & run
docker compose up --build

## Tests
docker compose exec app php artisan test

## Conventions
- Follow SOLID principles. Prefer small, focused classes with a single responsibility.
- Depend on abstractions, not implementations. Use interfaces at architectural boundaries.
- Keep business logic independent of framework, database, and infrastructure concerns.
- Write code for readability first.
- Keep methods small and expressive; favor clarity over cleverness.
- DRY, but do not abstract prematurely.
