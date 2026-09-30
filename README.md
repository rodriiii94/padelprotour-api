# PadelProTour API

API REST para la gestión de torneos y ligas de pádel: competiciones, categorías, parejas, inscripciones, fases, partidos, sets, clasificaciones y chat en tiempo real. Pensada como backend para una app móvil/web construida con Expo — autenticación 100% por token (sin sesión ni cookies).

## Stack

- **PHP 8.5** / **Laravel 13**
- **PostgreSQL**
- **Laravel Sanctum** — autenticación por token, un token por dispositivo
- **Laravel Reverb** — WebSockets para el chat en tiempo real
- **Pest** — tests

## Funcionalidades

- Auth por token (registro, login, logout, editar perfil) con rate limiting anti fuerza bruta.
- Competiciones (torneos y ligas), categorías, parejas e inscripciones (pareja fija o jugador individual con rotación).
- Fases, partidos y sets, con autorización organizador-only en toda la gestión.
- Generación automática de calendario round-robin para ligas de pareja fija (con bye automático si el número de parejas es impar).
- Cálculo de clasificación (ranking) con desempates (enfrentamiento directo, diferencia de sets, de juegos).
- Emparejamientos de jornada para ligas individuales con rotación.
- Chat de competición en tiempo real vía WebSocket (Laravel Reverb), restringido a organizador e inscritos.
- Búsqueda de usuarios para invitar compañero de pareja.
- Endpoints "mis competiciones" / "mis inscripciones" para no depender de conocer ids de antemano.

## Requisitos

- PHP 8.3+
- Composer
- PostgreSQL

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edita `.env` con los datos de tu base de datos PostgreSQL (`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) y crea la base de datos si no existe.

```bash
php artisan migrate
php artisan db:seed
```

`db:seed` carga un dataset de ejemplo completo (torneo, liga de pareja fija con calendario y ranking, liga individual con rotación, chat) pensado para desarrollar el frontend contra datos reales desde el primer momento.

**Login de demo:** `demo@padelprotour.test` / `password`

### Levantar la API

```bash
php artisan serve
```

### Levantar el chat en tiempo real

El chat necesita el servidor de Reverb corriendo aparte:

```bash
php artisan reverb:start
```

Sin esto, la API funciona con normalidad pero los mensajes de chat no se emiten por WebSocket (`POST /competitions/{id}/chat-messages` sigue guardando el mensaje igualmente).

## Documentación de la API

La especificación completa (todas las rutas, esquemas de petición/respuesta, autenticación) está en [`openapi.yaml`](openapi.yaml) — formato OpenAPI 3.0, importable directamente en Postman o cualquier visor Swagger/Redoc.

Autenticación: `Authorization: Bearer <token>` en cada petición a una ruta protegida. El token se obtiene en la respuesta de `POST /register` o `POST /login`.

## Servidor MCP (usar PadelProTour desde Claude)

La API expone también un servidor [MCP](https://modelcontextprotocol.io) en `POST /mcp` (transporte HTTP), hecho con `laravel/mcp`. Permite que Claude consulte y actúe sobre tus ligas en tu nombre, con **los mismos permisos que tienes en la app**: se autentica con el mismo token de Sanctum y reutiliza las mismas policies y reglas de negocio que la API REST.

| Herramienta | Qué hace | Tipo |
|---|---|---|
| `list_my_competitions` | Competiciones que organizo o en las que juego, con sus categorías | lectura |
| `get_competition` | Detalle de una competición: organizador, fechas, sede, categorías e inscritos | lectura |
| `get_standings` | Clasificación de una categoría | lectura |
| `list_my_matches` | Mis partidos (próximos, pendientes de validar o jugados) con rivales, sets y reserva de pista | lectura |
| `propose_match_result` | Propone el resultado de un partido en el que juego (un rival lo confirma después) | escritura |
| `update_match_booking` | Apunta día/hora, club, pista y enlace de Playtomic de un partido | escritura |

Ninguna herramienta devuelve emails. Sin token, `/mcp` responde `401`; limitado a 60 peticiones por minuto.

**Conectarlo a Claude Code** (el token es el de `POST /api/login`):

```bash
claude mcp add --transport http padelprotour https://api.padelprotour.net/mcp --header "Authorization: Bearer <token>"
```

En local, cambia la URL por `http://localhost:8000/mcp`. Para inspeccionarlo sin Claude: `npx @modelcontextprotocol/inspector`.

Código: servidor en [`app/Mcp/Servers/PadelProTourServer.php`](app/Mcp/Servers/PadelProTourServer.php), herramientas en [`app/Mcp/Tools`](app/Mcp/Tools), ruta en [`routes/ai.php`](routes/ai.php) y tests en [`tests/Feature/McpServerTest.php`](tests/Feature/McpServerTest.php).

## Tests

```bash
php artisan test --compact
```

Antes de dar por buena cualquier tanda de cambios en PHP:

```bash
vendor/bin/pint --dirty
```

## Notas de arquitectura

- **Sin Form Requests ni API Resources**: los controladores validan inline (`$request->validate()`) y devuelven los modelos Eloquent directamente — es el patrón que ya traía el proyecto y se ha mantenido por consistencia.
- **Autorización por Policies, reutilizadas hacia arriba en la jerarquía**: `Category`, `Phase`, `Match`, `MatchSet` y `MatchdayPairing` no tienen policy propia — reutilizan `CompetitionPolicy` comprobando la competición de la que cuelgan, porque ninguno tiene un dueño distinto del organizador de la competición.
- **Limitación conocida**: el cálculo de ranking en categorías de pareja fija atribuye cada lado de un partido a la `Pair` cuyos jugadores coincidan; si un partido se crea con jugadores que no forman ninguna pareja registrada, ese lado se omite del cálculo (no hay hoy validación en la creación de partidos que lo impida).
