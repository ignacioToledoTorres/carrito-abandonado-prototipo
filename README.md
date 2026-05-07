# Carrito Abandonado — Prototipo

Sistema de recordatorios por email para carritos abandonados. Envía correos automáticos a usuarios que dejaron un checkout en estado pendiente, en dos ventanas de tiempo distintas.

---

## Propuesta

Patrón **Template Method**: el abstract define el algoritmo completo de consulta, filtrado y marcado. Los providers concretos solo inyectan los valores que los diferencian (días de espera, tipo de email, columna de marca).

Esto permite agregar nuevos recordatorios (ej: 14 días) sin tocar la lógica central.

---

## Jerarquía de clases

```mermaid
classDiagram
    class AbstractEmailProvider {
        <<abstract>>
    }

    class AbstractAbandonedCartReminderMailProvider {
        <<abstract>>
        -string serviceCode
        -EntityManager entityManager
        +isEnabled(force) bool
        +getEntityClass() string
        +getCode() string
        +getFromName(entities) string
        +getFromEmail(entities) string
        +getToEmail(entities) string
        +getToName(entities) string
        +addQueryFields(builder, rootAlias) QueryBuilder
        +selectQueryFilter(builder, rootAlias, dateTimeExecution, force) QueryBuilder
        +onQueue(queue, groupedQueue) void
        +getTemplateName() string
        #getDaysThreshold()* int
        #getEmailType()* int
        #getReminderDqlField()* string
        #getReminderColumnName()* string
        #buildBody(entities)* string
        #buildSubject(entities)* string
    }

    class AbandonedCartTwoDaysReminderMailProvider {
        #getDaysThreshold() 2
        #getEmailType() ABANDONED_CART_TWO_DAYS
        #getReminderDqlField() reminderTwoDaysSentAt
        #getReminderColumnName() reminder_two_days_sent_at
    }

    class AbandonedCartSixDaysReminderMailProvider {
        #getDaysThreshold() 6
        #getEmailType() ABANDONED_CART_SIX_DAYS
        #getReminderDqlField() reminderSixDaysSentAt
        #getReminderColumnName() reminder_six_days_sent_at
    }

    AbstractEmailProvider <|-- AbstractAbandonedCartReminderMailProvider
    AbstractAbandonedCartReminderMailProvider <|-- AbandonedCartTwoDaysReminderMailProvider
    AbstractAbandonedCartReminderMailProvider <|-- AbandonedCartSixDaysReminderMailProvider
```

---

## Flujo de envío

```mermaid
flowchart TD
    A([Cron / Command ejecuta el provider]) --> B{isFeatureFlagEnabled?}
    B -- No --> Z([Skip — feature desactivada])
    B -- Sí --> C{"¿minutos actuales % 15 === 0?\n(ej: :00, :15, :30, :45)"}
    C -- No --> Z1([Skip — fuera de intervalo])
    C -- Sí --> D[selectQueryFilter\nConsulta TenantCheckout]

    D --> D1["status = PENDING\nupdatedAt < now() − N días\nreminderXxxSentAt IS NULL\nuser.email NOT NULL\nuser.enabled = true\nEXISTS item pendiente"]

    D1 --> E{¿Hay resultados?}
    E -- No --> Z2([Fin — nada que enviar])
    E -- Sí --> F[buildSubject + buildBody\nArma el contenido del email]

    F --> G[getFromName + getFromEmail\nObtiene remitente según idioma del usuario\nvía EmailType repository]

    G --> H[Email enviado al usuario]

    H --> I[onQueue\nDBAL transaction\nUPDATE tenant_checkout\nSET reminder_xxx_sent_at = now\nWHERE id IN ids enviados]

    I --> J{¿Transacción OK?}
    J -- Sí --> K([Fin exitoso])
    J -- No --> L([rollBack + throw Exception])
```

---

## Línea de tiempo por checkout

```mermaid
timeline
    title Vida de un carrito abandonado
    Día 0  : Usuario crea checkout
           : status = PENDING
    Día 2  : TwoDaysProvider lo detecta
           : Envía primer recordatorio
           : Marca reminder_two_days_sent_at
    Día 6  : SixDaysProvider lo detecta
           : Envía segundo recordatorio
           : Marca reminder_six_days_sent_at
```

---

## Diferencias entre los dos providers

| | `TwoDaysReminderMailProvider` | `SixDaysReminderMailProvider` |
|---|---|---|
| Días de espera | 2 | 6 |
| Campo DQL | `reminderTwoDaysSentAt` | `reminderSixDaysSentAt` |
| Columna DB | `reminder_two_days_sent_at` | `reminder_six_days_sent_at` |
| Tipo de email | `ABANDONED_CART_TWO_DAYS` | `ABANDONED_CART_SIX_DAYS` |

Ambos providers implementan exactamente los mismos métodos abstractos. La lógica de consulta, envío y marcado es 100% heredada del abstract.

---

## Métodos abstractos que cada provider debe implementar

```
getDaysThreshold()       → cuántos días deben haber pasado desde updatedAt
getEmailType()           → constante de Tenant para obtener config de email
getReminderDqlField()    → campo DQL para filtrar "aún no enviado"
getReminderColumnName()  → columna SQL para marcar "ya enviado"
buildBody()              → HTML/texto del cuerpo del email
buildSubject()           → asunto del email
```

---

## Garantía de no duplicados

El filtro `reminderXxxSentAt IS NULL` + el `UPDATE` en `onQueue()` forman una guarda idempotente: un checkout que ya recibió su recordatorio de 2 días nunca vuelve a aparecer en la consulta de 2 días. Los recordatorios de 2 días y 6 días son independientes entre sí.
