# 📦 REGLA GLOBAL DE GOBERNANZA: MANIFIESTO OBLIGATORIO Y TAREAS POST-PASO

> Aplica obligatoriamente a todo desarrollo asistido por IA (`@dev-php`, `@dev-salesforce`, `@dev-frontend`, `@db-migration-architect`) previo a la apertura de un Pull Request o despliegue.

---

### 1. 🛡️ Principio de Auto-Contención de la Solución
Toda entrega de software asociada a un correlativo `SOL-XXXX` debe ser **100% autocontenida y gobernable**. Queda estrictamente prohibido entregar código sin su manifiesto formal de dependencias y su catálogo de actividades post-despliegue.

---

### 2. 📋 Manifiesto de Despliegue Obligatorio

#### A. En Proyectos Salesforce (Banco Vimenca, Banco Santa Cruz, etc.):
- **Archivo de Manifiesto (`manifest/package.xml`):**
  - Debe incluir el 100% de los componentes modificados o creados (`ApexClass`, `ApexTrigger`, `LightningComponentBundle`, `CustomField`, `CustomObject`, `PermissionSet`, `Layout`, `Flow`).
  - **Inspección de Dependencias Invisibles:** Si un componente utiliza un `FieldSet`, `CustomMetadata` (`__mdt`), `CustomSettings` (`__c`) o `CustomLabel`, **deben estar incluidos explícitamente en el manifiesto**.
  - **Permisos y FLS:** Todo nuevo campo u objeto debe acompañarse de sus asignaciones de permisos en los `PermissionSets` correspondientes dentro del manifiesto.

#### B. En Proyectos Web / Backend PHP (Sin Notario, KeyHub, Surges):
- **Archivo de Manifiesto (`manifest/deploy_manifest.json`):**
  - Lista exhaustiva de archivos agregados/modificados.
  - Declaración de migraciones de esquema SQL requeridas (idempotentes con `CREATE TABLE IF NOT EXISTS` o validación previa de columnas).
  - Scripts o dependencias añadidas.

---

### 3. 📝 Plan Obligatorio de Actividades Post-Paso (Post-Deploy Checklist)
Junto al manifiesto, el agente debe generar una sección titulada **`📋 Actividades Post-Paso & Configuración Runtime`** que se incluirá en el Pull Request y en la tarjeta de KeyHub:
1. **Configuraciones Manuales Requeridas:**
   - Creación o actualización de registros en Custom Metadata / Settings (ej. URLs de endpoints, credenciales API, variables de entorno).
   - Asignación de Permission Sets a usuarios o perfiles clave.
   - Activación de FlexiPages o asignación de Page Layouts por perfil y tipo de registro.
2. **Parámetros de Integración Externa:**
   - Nuevos Webhooks, credenciales en Remote Site Settings / Named Credentials, o variables en `.env`.
3. **Verificación Operativa Inmediata (Smoke Test Target):**
   - URL o endpoint clave a probar inmediatamente después de la sincronización para descartar Errores 500 o fallos de ejecución.

---

### 4. 🛑 Criterio de Bloqueo (Quality Gate)
- Todo Pull Request que no declare el manifiesto actualizado o carezca del bloque de **Actividades Post-Paso** será observado por `@qa-code-reviewer` y el pipeline de CI/CD, impidiendo la fusión a ramas superiores (`qa` y `main`).
