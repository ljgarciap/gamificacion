-- Base separada para los tests: usan RefreshDatabase y borran todo, nunca deben tocar la de desarrollo.
-- Solo se ejecuta al inicializar el volumen. Si el volumen ya existía:
--   docker compose exec db psql -U gamification -c "CREATE DATABASE gamification_test"
CREATE DATABASE gamification_test;
