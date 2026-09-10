# Cambios de API pendientes de integrar en el frontend

Desde el último handoff (competiciones privadas + invitación), se han añadido 4
cosas más. Todo está en `main`, documentado en [`openapi.yaml`](openapi.yaml)
(fuente de verdad para tipar el cliente) y cubierto por tests.

## 1. Nombres de jugador embebidos (ya no hace falta resolver IDs)

`GET /pairs`, `POST /pairs`, `GET /pairs/{pair}`, `GET /categories/{category}/registrations`,
`GET /registrations/{registration}`, `GET /phases/{phase}/matches`,
`GET /matches/{match}` y la respuesta de `POST /categories/{category}/round-robin`
ahora incluyen los datos del jugador (`{ id, name }`) junto a los `*_id` que ya
había (los IDs se mantienen, no se han quitado):

- `Pair.player1` / `Pair.player2`
- `Registration.player` (si `player_id` no es null) / `Registration.pair` (si
  `pair_id` no es null, con `pair.player1`/`pair.player2` ya resueltos dentro)
- `Match.side1_player1` / `side1_player2` / `side2_player1` / `side2_player2`

**Qué hacer:** si tenéis algún workaround para resolver nombres a partir de
IDs (llamadas a `searchUsers()`, caché local, "Jugador #12"...), podéis
quitarlo y leer directamente `pair.player1.name`, `registration.player.name`,
`match.side1_player1.name`, etc.

**Ojo:** esto **no** aplica a `POST`/`PUT` de `Registration` ni `Match` — esas
respuestas siguen devolviendo solo los `*_id`, sin los objetos embebidos.

## 2. Filtro `?upcoming=1` en `GET /competitions`

```
GET /competitions?upcoming=1
```

Excluye competiciones cuyo `end_date` ya pasó (las que no tienen `end_date`
siempre se incluyen). **Qué hacer:** si estáis filtrando "no finalizadas" en
el cliente después de paginar, podéis quitar ese filtro y pasar
`upcoming=1` en la query — la paginación ya viene correcta.

## 3. Competiciones privadas + enlace de invitación

- `Competition` tiene `is_private` (bool) e `invite_token` (string, **solo
  visible si eres el organizador** — no aparece la clave en absoluto para
  cualquier otro caller, ni siquiera como `null`).
- `GET /competitions` nunca devuelve privadas, ni las tuyas propias — para
  verlas usa `GET /me/competitions` (sin cambios en esa llamada).
- `POST /competitions` acepta `is_private` (default `false`); si es `true`,
  la respuesta trae `invite_token` para que el organizador comparta el
  enlace.
- Nuevo: `GET /invites/{token}` (autenticado, rate-limited) — resuelve la
  competición por su token de invitación sin pasar por la comprobación
  normal de privacidad. Úsalo para la pantalla "unirse por enlace": el
  usuario abre el link, veis los datos de la competición, y luego seguís el
  flujo normal de `POST /categories/{category}/registrations` para
  inscribirse — este endpoint no inscribe a nadie automáticamente.
- Ver una competición privada sin ser organizador ni participante da `403`.

**Qué hacer:** añadir toggle "privada" al crear competición, mostrar/copiar
el `invite_token` (como URL tipo `https://tuapp.com/invite/{token}`) solo
cuando el usuario actual sea el organizador, y una pantalla que reciba ese
token y llame a `GET /invites/{token}`.

## 4. La clasificación ya se recalcula sola

Antes había que llamar a `POST /categories/{category}/rankings/recalculate`
después de cargar un resultado. Ya no: al hacer
`PUT /matches/{match}` con `status: completed` (+ `winner_side`), el backend
recalcula la clasificación de esa categoría automáticamente en la misma
petición.

**Qué hacer:** si el flujo de "organizador registra resultado" llamaba a
`/recalculate` después de completar el partido, esa llamada ya es
redundante — podéis quitarla. El endpoint de `recalculate` sigue existiendo
por si algún día hace falta forzar un recálculo manual (p.ej. tras corregir
un set de un partido ya completado), pero ya no es parte del flujo normal.

## 5. Login social (Google + Apple) y verificación de email obligatoria

**Cambio que rompe compatibilidad**: `POST /register` **ya no devuelve token**.
Crea la cuenta sin verificar, manda un email de verificación, y responde
`{ message, user }` (201) sin `token`. `POST /login` ahora da `403` si la
cuenta no está verificada (`email_verified_at` es `null`).

- **Verificación por email**: el email que mandamos incluye un botón/enlace
  con un deep link: `padelprotour://verify-email?token={token}`. **Necesito
  que configuréis ese esquema en `app.json`** (`"scheme": "padelprotour"`) y
  que la app, al abrir ese link, lea el `token` de la query string y llame a:
  - `POST /email/verify` — body `{ token, device_name }` → `{ user, token }`
    (verifica y loguea en la misma llamada, no hace falta un paso aparte).
  - `POST /email/resend` — body `{ email }` → reenvía el email si la cuenta
    existe y no está verificada (respuesta genérica siempre, no revela si el
    email existe).
- **Google**: la app hace el login nativo con el SDK de Google ella misma
  (no es un flujo de redirect contra nuestro backend) y nos manda el
  `id_token` resultante:
  - `POST /auth/google` — body `{ id_token, device_name }` → `{ user, token }`.
    Cuenta nueva o existente, ya verificada automáticamente (Google ya
    verificó el email). `409` si el email ya tiene cuenta por otro método
    (no fusionamos automáticamente, por seguridad).
- **Apple**: igual que Google, pero con una particularidad — **Apple solo
  manda el nombre real la primera vez que el usuario autoriza, fuera del
  token** (en la respuesta nativa del SDK de `expo-apple-authentication`, no
  dentro del JWT). Hay que capturarlo en ese primer login y mandarlo:
  - `POST /auth/apple` — body `{ id_token, device_name, name? }` (`name`
    solo en el primer login de cada usuario; se ignora en los siguientes).

**Configuración pendiente por vuestro lado** (yo ya dejé las variables listas
en el backend, pero necesito los valores reales):
- Google: el/los Client ID(s) de OAuth (iOS + Android si son distintos) →
  me los pasáis y los meto en `GOOGLE_CLIENT_IDS`.
- Apple: el **Bundle ID** de la app (no un Service ID — el login nativo usa
  el bundle id como `aud` del token) → lo meto en `APPLE_CLIENT_IDS`.
- Confirmar el esquema de deep link si `padelprotour://verify-email` no os
  vale por lo que sea (aún no estaba definido en el frontend, así que elegí
  ese por defecto).

**Nota de infra**: en local los emails de verificación no salen de verdad
(`MAIL_MAILER=log`, se ven en el log del backend) hasta que configuremos un
mailer real — igual que pasó con Reverb al principio.

---

Todo lo anterior está probado (tests de feature) y documentado en
`openapi.yaml`. Si algo no cuadra con el comportamiento real, es un bug —
avisad.
