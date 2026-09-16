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
  con un deep link: `padelontourapp://verify-email?token={token}` (mismo
  esquema `padelontourapp` que ya usáis para el enlace de invitación — ya no
  hace falta registrar nada nuevo en `app.json`). La app, al abrir ese link,
  lee el `token` de la query string y llama a:
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

**Nota de infra**: en local los emails de verificación no salen de verdad
(`MAIL_MAILER=log`, se ven en el log del backend) hasta que configuremos un
mailer real — igual que pasó con Reverb al principio.

## 6. Tres sueltas pedidas en el último informe, ya resueltas

- **Deep link de verificación**: corregido al esquema `padelontourapp` (ver
  punto 5 arriba) — ya no hace falta ningún cambio de vuestro lado, solo
  que el link que llegue por email use `padelontourapp://verify-email?token=...`
  en vez del esquema anterior.
- **Nombre de pareja**: `POST /pairs` acepta `name` opcional (string, hasta
  255) junto a `partner_id`. Aparece como `Pair.name` (nullable) en todas
  las respuestas donde ya sale `Pair` — `GET /pairs`, `POST /pairs`,
  `GET /pairs/{pair}`, y embebido en `Registration.pair`. No hay endpoint
  para cambiarlo después de crear la pareja (no se pidió). `Ranking` y
  `Match` siguen igual, identificando solo por `pair_id` — para mostrar el
  nombre ahí hay que cruzar con la pareja correspondiente, como ya decíais
  que ibais a hacer.
- **Categorías por enlace de invitación**: nuevo `GET /invites/{token}/categories`
  — misma idea que `GET /invites/{token}` (conocer el token es la
  autorización, sin pasar por la policy normal), devuelve el array de
  `Category` de esa competición. `POST /categories/{category}/registrations`
  no necesita ningún cambio — ya no comprobaba privacidad.

## 7. Validación de resultados de set (mejor de 3, marcador válido de pádel)

Hasta ahora `POST /matches/{match}/sets` y `PUT /sets/{set}` aceptaban
cualquier `set_number` y cualquier `side1_games`/`side2_games` — se podían
registrar más de 3 sets o marcadores que no existen en pádel (`9-0`, `0-7`,
`5-5`...). Ya está corregido en el backend, con `422` en los casos
inválidos, pero la pantalla de resultado (la de la captura que mandasteis)
deja escribir esos valores libremente y solo falla al guardar — hay que
ajustar la UI para que no llegue a ese punto.

**Reglas que ahora aplica el backend:**
- `set_number` solo puede ser `1`, `2` o `3`.
- No se puede añadir un set nuevo si un lado ya ha ganado 2 (partido
  decidido al mejor de 3 — no hace falta un tercer set).
- Un resultado de set solo es válido si es: 6 juegos con 2 de diferencia
  (`6-0` a `6-4`), `7-5`, o `7-6` (tie-break). Cualquier otra combinación
  (incluido un empate como `5-5`, o `6-5` sin resolver) da `422`.

**Formato del error:** ojo, no todos son errores de validación "de campo".
- `set_number` fuera de rango sí es un error de validación normal:
  `422` con `{ message, errors: { set_number: [...] } }`.
- "resultado no válido en pádel" y "partido ya decidido" son reglas de
  negocio (`abort_if`/`abort_unless`), no de un campo concreto: `422` con
  solo `{ message }` (sin `errors`). No asumáis que `errors.side1_games`
  vendrá siempre relleno — mostrad `message` como fallback genérico.

**Qué hacer:**
- No mostrar el input del set 3 (ni permitir añadir más filas) en cuanto un
  lado lleve 2 sets ganados con los sets ya introducidos — el partido está
  decidido, ocultad el resto en vez de esperar al `422`.
- Limitar los selectores/inputs de juegos por set a los marcadores válidos
  de arriba, en vez de un campo numérico libre — evita el `9-0` o `5-5` de
  la captura antes de mandar la petición.
- Si aun así llega un `422`, mostrar `message` tal cual (ya viene en
  castellano, listo para el usuario) en lugar de un error genérico.

## 8. Cancelar competición (nuevo, distinto de eliminar)

- `Competition` tiene `cancelled_at` (nullable). `DELETE /competitions/{id}`
  ya existía (borra todo en cascada, irreversible); ahora además
  `POST /competitions/{id}/cancel` (organizador, `422` si ya está
  cancelada) la marca sin borrar nada.
- Una cancelada desaparece de `GET /competitions` pero sigue en
  `GET /me/competitions` — y `POST /categories/{id}/registrations` da
  `422` si la competición de esa categoría está cancelada.
- Esta vez el botón lo monto yo mismo en la app (pantalla de detalle de
  competición), no hace falta que lo hagáis vosotros.

---

Todo lo anterior está probado (tests de feature) y documentado en
`openapi.yaml`. Si algo no cuadra con el comportamiento real, es un bug —
avisad.
