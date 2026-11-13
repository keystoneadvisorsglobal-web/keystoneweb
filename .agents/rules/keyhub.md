---
trigger: always_on
---

# 🚀 REGLAS DE WORKSPACE: KEYHUB (KEYSTONE ADVISORS)

> Aplica cuando se trabaje en proyectos, código o archivos bajo `Keystone Advisors/KeyHub/`.

### 1. 🔢 Ciclo Estricto de Soluciones y Correlativos (`SOL-XXXX`)
- **Consulta Previa Obligatoria**: Antes de iniciar o nombrar cualquier cambio, consultar `CONTROL_CORRELATIVOS.md` para tomar el correlativo consecutivo inmediato.
- **Registro Inmediato**: Asentar cada solución en la tabla de `CONTROL_CORRELATIVOS.md` indicando fecha, alcance exacto y estado.
- **Bitácora de Producción**: Todo despliegue a producción debe quedar registrado en `BITACORA_PRODUCCION.md` con su fecha, ambiente, `SOL-XXXX` y un párrafo resumen del resultado funcional y pruebas validadas.
- **Verificación Operativa Post-Paso (Smoke Test Anti-500)**: Inmediatamente después de completar un paso a producción, es MANDATORIO verificar que la plataforma se encuentre 100% operativa (inspeccionar respuestas HTTP 200, cargar la vista principal y probar un endpoint clave de la API) para descartar caídas, errores de sintaxis o fallos de base de datos que deriven en Error 500 (Internal Server Error).

### 2. 🏗️ Arquitectura de Software y Separación de Capas (PHP / JS / CSS)
- **Capa Backend (`api/index.php`)**:
  - Toda la lógica de negocio, endpoints REST y acceso a datos residen exclusivamente en `/api/` y `Database.php`. Prohibido incrustar consultas SQL en `index.php` (frontend) o archivos de vista.
  - La respuesta de la API siempre debe ser JSON estándar estructurado: `{"success": true, "data": ...}` o `{"success": false, "error": "Mensaje claro"}` con su código HTTP correspondiente.
- **Capa Frontend (`assets/js/app.js` & `index.php`)**:
  - Toda interacción asíncrona se maneja vía `fetch()` en `app.js` controlando estados de carga (`spinner`/`disabled`) y manejo de excepciones.
  - En formularios y componentes de búsqueda/filtro, respetar los blindajes anti-autofill implementados (ej. `contenteditable role=textbox` o atributos no estándar para evitar que gestores de contraseñas corrompan los inputs).
- **Estilos (`assets/css/style.css`)**:
  - Mantener coherencia con la paleta oscura/clara corporativa, z-index ordenados (evitar colisiones en modales superpuestos) y diseño responsive.

### 3. 🗄️ Base de Datos SQLite y Migraciones Seguras (`database/Database.php`)
- **Migraciones Idempotentes**: Toda modificación al esquema de base de datos debe añadirse en `Database::initSchema()` con sintaxis segura (`CREATE TABLE IF NOT EXISTS`, o validaciones previas antes de `ALTER TABLE ADD COLUMN`) para ejecutarse sin romper bases de datos existentes en caliente.
- **Integridad de Datos y Prepared Statements**:
  - Uso obligatorio y estricto de sentencias preparadas con PDO (`prepare` / `execute` con bind parameters) para el 100% de las consultas con parámetros dinámicos. Prohibida la concatenación de variables en SQL.
  - Habilitar siempre `PRAGMA foreign_keys = ON;` para preservar la integridad referencial.
- **Respaldo Previo**: Ante migraciones de esquema complejas o ingestas masivas de datos, asegurar o generar respaldo de `database/keyhub.sqlite`.

### 4. 📄 Módulos Críticos: Contratos, Horas y Tablero Kanban
- **Módulo de Contratos y Legal**:
  - Cuidar los parámetros de sustitución dinámica (`{{NOMBRE_COMPLETO}}`, `{{RUT_DNI}}`, `{{DIRECCION_LEGAL}}`, `{{REPRESENTANTE_LEGAL}}`, etc.) en las plantillas y adendas.
  - Respetar la integridad de las cláusulas de blindaje: NDA agravado, Cláusula Penal, No Competencia y validez de firma digital multinacional.
- **Horas e Imputaciones**:
  - Garantizar la trazabilidad del usuario, moneda (`USD` vs `CLP`), cálculo de totales líquidos y compatibilidad con vistas divididas (privada vs directores).
- **Tablero Kanban**:
  - Asegurar la persistencia del estado en recarga (`localStorage` y parámetro `?board=`), preservación del scroll post-guardado y trazabilidad en la cadena de custodia/historial de tarjetas.

### 5. 🔍 Verificación Sintáctica y Control de Versiones
- **PHP Lint Obligatorio**: Ejecutar `php -l <archivo.php>` en cada script PHP tocado antes de dar por cerrada la tarea o subir a producción.
- **Git Hygiene**: Los cambios deben quedar ordenados, sin dejar archivos temporales (`.bak`, `.tmp`) ni credenciales expuestas en el repositorio.

### 6. 🐛 Gobernanza QA: Registro de Tarjetas BUG y Trazabilidad en Tableros
- **Pregunta y Confirmación Obligatoria de Registro**:
  - Al detectar, reportar o analizar cualquier defecto, desviación o comportamiento anómalo como agente de QA, **es MANDATORIO preguntar activamente al usuario si requiere registrar la tarjeta de `BUG` en el tablero KeyHub del proyecto**, vinculada a la tarjeta madre (Historia de Usuario, Tarea o Requerimiento) y/o al Caso de Prueba correspondiente.
- **Identificación del Tablero del Proyecto**:
  - Identificar siempre el proyecto y tablero destino en `keyhub.sqlite` (`projects.project_key` / `projects.id`, ej.: `SN` para Sin Notario, `BVAW` para Apertura Web, `BVOM` para Onboarding Masivo, etc.).
- **Resolución Proactiva de Dudas**:
  - Si no está clara la tarjeta madre (`parent_card_id`), el caso de prueba o el tablero objetivo, **NO asumir a ciegas ni dejar la tarea en el limbo del chat**: se debe formular una pregunta clara o de selección (vía texto o `ask_question`) para confirmar:
    1. El tablero/proyecto de KeyHub.
    2. El código de la tarjeta madre asociada (ej. `SN-101`).
    3. La severidad y columna de destino (`TODO` / `IN_CORRECTION`).
- **Registro Efectivo y Persistente (Estándar de Calidad QA)**:
  - Una vez confirmada la acción, la tarjeta de `BUG` debe insertarse tanto en la base de datos de KeyHub de producción como local, cumpliendo rigurosamente los siguientes atributos:
    1. **Trazabilidad y Jerarquía**: `card_code` correlativo del proyecto (ej. `SN-102`), `parent_card_id` apuntando a la tarjeta madre (ej. `SN-101`).
    2. **Transición Obligatoria de Tarjeta Madre a Corrección**:
       - Toda declaración de un `BUG` asociado a una tarjeta madre debe mover **invariablemente** la tarjeta madre a la columna del proyecto configurada como etapa de corrección (`SELECT column_key FROM project_columns WHERE project_id = ? AND is_correction_stage = 1`, habitualmente `IN_CORRECTION`).
       - Actualizar `status` y `status_changed_at = datetime('now')` de la tarjeta madre de inmediato, tanto si la inserción se realiza vía API como por CLI/SQL directo.
    3. **Fecha de Inicio (`start_date`)**: Debe registrarse con la fecha del día de creación del defecto (`date('now')`).
    4. **Asignación Automática (`assigned_users`)**: Asignar por defecto al desarrollador o responsable que originó el desarrollo de la tarjeta madre (`parent_card.assigned_users`).
    5. **Severidad Obligatoria (`severity`)**: Clasificar explícitamente el impacto (`BLOCKER`, `CRITICAL`, `MAJOR`, `MINOR`), evitando valores nulos o genéricos.
    6. **Evidencias y Adjuntos (`card_attachments`)**: Si el usuario o el reporte disponen de capturas o archivos adjuntos, deben transferirse al directorio de uploads (`uploads/attachments/`) y registrarse formalmente en la tabla `card_attachments` vinculados a la tarjeta de bug para consulta del desarrollador.

### 7. 🛠️ Estándar de Corrección de Bugs y Trazabilidad en Tarjetas (Dev & Agentes IA)
Toda intervención correctiva sobre una tarjeta de defecto (`BUG`), ya sea ejecutada de forma interactiva por un desarrollador o de manera autónoma por un agente especialista (`@dev-php`, `@dev-frontend`, `@dev-salesforce`, etc.), debe cumplir obligatoriamente con el siguiente ciclo:

1. **Estimación Inicial de Esfuerzo (`estimated_hours`)**:
   - Al pasar la tarjeta a `IN_PROGRESS`, es mandatorio asignar de inmediato una estimación inicial de horas en el campo `estimated_hours` (ej. `0.5` o `1.0`), reflejando una meta tangible en la barra de tiempo del tablero KeyHub.
2. **Bitácora Transparente en Comentarios (`card_comments`)**:
   - Queda prohibido el trabajo "a ciegas". Se deben registrar comentarios de avance en la pestaña de comentarios de la tarjeta en cada hito clave:
     - **Inicio:** Notificar la toma de control de la tarjeta y el análisis de dependencias/criterios de aceptación (DoD).
     - **Avance / Testing:** Reportar la solución técnica implementada y la ejecución exitosa de Quality Gates (`php -l` sin errores, pruebas unitarias o linter).
     - **Cierre Técnico:** Resumir los cambios aplicados antes de transferir el ticket.
3. **Transición a QA y Notificación Final**:
   - Una vez validados los cambios, mover la tarjeta a la columna de aseguramiento de calidad (`READY_QA` o `Listo para QA`).
   - Disparar la notificación final de término al desarrollador humano responsable vía WhatsApp (usando la instancia asignada al tablero y el número del dev), indicando el código de la tarjeta, resumen de la corrección y enlace al tablero KeyHub.

### 8. 🔄 Protocolo Mandatorio de Promoción entre Ambientes y Entrega a QA (DEV ➔ Release ➔ QA ➔ Human Gate)
Queda estrictamente prohibido mover una tarjeta a `READY_QA` sin haber cumplido secuencialmente las siguientes cinco (5) etapas:

1. **Construcción y Testing Local (DEV):**
   - El agente o desarrollador asignado (`@dev-php`, `@dev-salesforce`, `@dev-frontend`) implementa la solución exclusivamente en su entorno local/sandbox de desarrollo.
   - Ejecuta Quality Gates obligatorios: verificación sintáctica (`php -l`), linter JS o cobertura de tests unitarios Apex >= 85%.

2. **Entrega Formal al Release Manager (`@release-manager`):**
   - El Dev empaqueta el manifiesto (archivos tocados, correlativo `SOL-XXXX` y dependencias) y solicita formalmente al `@release-manager` la promoción hacia el ambiente **QA** del proyecto.
   - Prohibido desplegar directamente a QA sin validación previa o saltarse el gatekeeper.

3. **Promoción y Despliegue en QA:**
   - El `@release-manager` ejecuta la validación previa en seco (`dry-run` o `validate`), aplica el despliegue al servidor/org de QA y registra la evidencia en la cadena de custodia.

4. **Smoke Test y Auto-Validación por el Dev en QA:**
   - Una vez finalizado el despliegue en QA, el **mismo desarrollador que originó el cambio** debe inspeccionar el ambiente de QA (endpoint HTTP 200, prueba de interfaz o ejecución de prueba de humo) para certificar que su corrección está efectivamente operativa en dicho entorno.

5. **Pase a 'Listo para QA' (Human-in-the-Loop Gate):**
   - Solo tras confirmar el punto anterior, el Dev publica un comentario en la tarjeta resumiendo la prueba exitosa en QA y mueve la tarjeta a la columna de espera humana **`READY_QA` ("Listo para QA")**.
   - Queda estrictamente en manos del tester humano o Lead arrastrar la tarjeta a `IN_QA` para la certificación final.
