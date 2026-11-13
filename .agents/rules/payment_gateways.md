# 💳 REGLAS DE WORKSPACE: PASARELAS DE PAGO & MERCADO PAGO

> Aplica a cualquier proyecto o módulo que integre pasarelas de pago online (Mercado Pago, Khipu, Webpay, etc.).

---

### 1. 🔍 Detección Temprana de Arquitectura API en Mercado Pago (Orders API vs Preferences API)
- **Verificación Obligatoria de API Integrada**: Antes de implementar o generar credenciales en Mercado Pago, se debe verificar el tipo de API seleccionada en la aplicación del Developer Panel (`API integrada`):
  - **Orders API (`/v1/orders`)**: Esquema moderno de Checkout Pro. Requiere:
    - Encabezado HTTP obligatorio `X-Idempotency-Key`.
    - Payload estricto con campos `type: "online"`, `processing_mode: "automatic"`, bloque `transactions.payments` con montos en string (`"3490"` en lugar de enteros numéricos), y supresión de `currency_id` en los ítems individuales.
    - El resultado del checkout entrega un `order_id` al que deben apuntar las validaciones y los webhooks.
  - **Preferences API (`/checkout/preferences`)**: Esquema clásico de Checkout Pro. Requiere `init_point`, `sandbox_init_point`, array de `items` con `currency_id: "CLP"` y retorno por `preference-id`.
- **Prohibición de Mezcla**: Queda estrictamente prohibido enviar payloads de Preferences a una app registrada como Orders (o viceversa), ya que Mercado Pago Sandbox abortará el flujo con redirección a `/fatal`.

---

### 2. 🛡️ Segregación Estricta de Entornos (Sandbox vs Producción)
- Mantener siempre aisladas las llaves públicas, access tokens y secretos de webhook para cada ambiente (`_sandbox` vs `_production`).
- En Sandbox, asegurar que las pruebas de pago se ejecuten con usuarios de prueba compradores (`test_user` generados por la misma aplicación) o en ventanas privadas/incógnito para evitar colisiones con cuentas reales del vendedor.

---

### 3. 📝 Trazabilidad y Conciliación
- Registrar cada orden de cobro en tabla relacional (`payment_orders`) guardando el identificador retornado (`preference_id` u `order_id`), montos netos, IVA, tipo de transacción y estado para reconciliación vía Webhook.

