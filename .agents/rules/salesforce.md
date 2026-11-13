---
trigger: always_on
---

# ⚡ REGLAS GLOBALES DE WORKSPACE: SALESFORCE & DESPLIEGUES

> Aplica a cualquier proyecto, carpeta o solución sobre Salesforce (Banco Santa Cruz, Banco Vimenca, FSC, Core CRM, etc.).

---

### 1. 🔍 Check de Dependencias Dinámicas e Invisibles (Pre-Manifiesto)
- **Inspección de Referencias Implícitas**: Cuando un componente LWC, Aura o clase Apex consuma `Schema.FieldSet`, `CustomMetadata` (`__mdt`), `CustomSettings` (`__c`), `Labels` (`Label.`), o `QuickActions`:
  - Es **ESTRICTAMENTE OBLIGATORIO** contrastar la definición en el Sandbox de origen vs. el Ambiente Destino / Producción mediante CLI (`sf project retrieve start` o queries de tooling/metadatos) antes de dar por cerrado el manifiesto.
  - Verificar que existan tanto el contenedor (objeto/definición) como los **registros de Custom Metadata o Settings** requeridos en runtime para que la lógica de Apex no devuelva `NullPointerException` en producción.
- **Campos en FieldSets**: Todo campo referenciado dentro de un `FieldSet` debe estar explícitamente incluido en el paquete (`CustomField`), incluyendo campos de objetos estándar o FSC (`Account`, `Contact`, `FinancialAccount__c`).

---

### 2. 🛡️ Seguridad, Accesibilidad y Permisos (FLS, Apex, Layouts & Perfiles)
- **Análisis Obligatorio del Ambiente Destino (PROD / QA)**:
  - Antes de empaquetar o desplegar cualquier componente (`ApexClass`, `CustomField`, `CustomObject`, `Layout`), es **MANDATORIO inspeccionar y contrastar** contra el ambiente destino qué perfiles y permission sets existen y tienen asignación activa.
- **Permission Sets y Perfiles (Apex & FLS)**:
  - Ningún campo nuevo (`CustomField`), objeto (`CustomObject`) o controlador Apex (`ApexClass` con métodos `@AuraEnabled`) puede desplegarse sin su correspondiente asignación de permisos (FLS - Field Level Security y Apex Class Access) en los Permission Sets y/o Perfiles correspondientes en el destino.
  - Validar que los usuarios finales tengan acceso de lectura/edición antes de probar en el ambiente destino.
- **Asignación y Permisos de Page Layouts por Perfil**:
  - Al crear o modificar un Page Layout (`Layout`), **nunca desplegarlo aislado**: se debe verificar e incluir la asignación de dicho Layout (`layoutAssignments`) para cada Perfil objetivo en el destino.
  - Asegurar que los perfiles clave (ej. administradores, ejecutivos, asesores) tengan mapeado el Layout correcto para el tipo de registro (`RecordType`) correspondiente en el ambiente destino.
- **Componentes LWC y Apex Controllers**:
  - Verificar que toda clase controladora invocada desde LWC (`@AuraEnabled`) cuente con la cláusula `with sharing` o `inherited sharing` explícita, y que sus dependencias no queden huérfanas.

---

### 3. 🧩 Presentación Frontend e Integración en Páginas (LWC / FlexiPages / Layouts)
- **Checklist Visual y Asignación de Layouts**: Antes de dar por completado un despliegue con interfaz de usuario:
  - Validar que la **Lightning Page / FlexiPage** activa referencie el componente nuevo o actualizado.
  - Si el componente va en un **Page Layout** (p. ej. botón, acción rápida `QuickAction` o lista relacionada), el Page Layout modificado debe formar parte del `package.xml`.
  - **Asignación de Layout a Perfiles**: Garantizar que el paquete incluya las asignaciones (`layoutAssignments` en los perfiles) hacia el nuevo/modificado Layout para evitar que el ambiente destino mantenga la vista previa desactualizada.
  - Comprobar que los metadatos de configuración del LWC (`*.js-meta.xml`) tengan los `targets` correctos (`lightning__RecordPage`, `lightning__AppPage`, etc.) y `isExposed` en `true`.

---

### 4. 🧪 Homologación Rigurosa de Tests Unitarios (Apex Coverage)
- **Ejecución Local Previa**: Toda clase de prueba (`*Test.cls`) debe ejecutarse y pasar al 100% en el entorno de desarrollo antes de generar el paquete:
  ```bash
  sf apex run test --tests MiClaseTest --result-format human --code-coverage
  ```
- **Assertions Reales y Cobertura**: Prohibido usar `System.assert(true)` vacío. Debe alcanzarse un mínimo de **85% de cobertura real** sobre la lógica modificada, validando flujos positivos, negativos y casos límite (bulkification).
- **Test Suited para Producción**: Identificar de antemano el nivel de ejecución requerido para producción (`RunSpecifiedTests` con la lista explícita de clases de test del cambio) para evitar bloqueos por tests rotos preexistentes en la org.

---

### 5. 📦 Integridad del Manifiesto (`package.xml`) y Validación (CheckOnly)
- **Prohibición de Deltas Rotos**: El manifiesto (`package.xml` / `destructiveChanges.xml`) debe contener el 100% de los componentes y dependencias. No se permiten despliegues fragmentados que dejen el ambiente destino en estado inconsistente.
- **Validación Previa Obligatoria (`--dry-run` / `--test-run`)**:
  - Todo pase a ambientes superiores (MergeQA, QA, UAT o Prod) debe ejecutarse primero en modo **validación sin commit** (`--dry-run` / `sf project deploy validate`):
  ```bash
  sf project deploy validate --manifest manifest/package.xml --target-org <alias-destino> --test-level RunSpecifiedTests --tests <TestClasses>
  ```
  - Solo si la validación retorna `Status: Succeeded` se autoriza el despliegue definitivo.
- **Registro Post-Despliegue**: Registrar el resultado en `CONTROL_CORRELATIVOS.md` y `BITACORA_PRODUCCION.md` con su `SOL-XXXX`.

---

### 6. 🔄 Protocolo Estricto de Ambientes y Cadena de Promoción (DEV ➔ QA ➔ PROD)
- **Despliegue Obligatorio Previo en DEV**:
  - Queda **ESTRICTAMENTE PROHIBIDO** promover código, triggers o LWC directamente a QA (ej. `vimenca-calidad`) sin antes haberlo desplegado, validado y probado al 100% en el ambiente de Desarrollo correspondiente del cliente:
    - **Banco Vimenca**: Ambiente DEV = `vimenca-sandbox` (`vimenca--mergeqa`).
    - **Banco Santa Cruz**: Ambiente DEV = `bsc-dev` / `mergeqa`.
  - El agente desarrollador (`@dev-salesforce`) debe verificar que el entorno DEV compile sin errores, no tenga dependencias de metadatos o campos faltantes (`CustomField`, etc.), y supere los tests unitarios.
- **Cadena de Custodia Git y Release Manager**:
  - Todo cambio debe nacer en una rama `feature/` o `fix/` del repositorio Git del proyecto específico (ej. `bancovimenca/war-room`). Prohibido mezclar URLs o repositorios entre clientes (ej. Sin Notario vs Vimenca vs Santa Cruz).
  - La promoción a QA la ejecuta formalmente el `@release-manager` únicamente tras la validación exitosa en DEV.
- **Prohibición de Simulaciones**:
  - Todos los comentarios de despliegue en KeyHub deben respaldarse con el `Deploy ID` real emitido por Salesforce CLI (`sf project deploy ...`). Queda terminantemente prohibido generar logs o comentarios simulados.
