# 🎭 REGLA GLOBAL: PROTOCOLO DE EVIDENCIA VISUAL QA E2E & ADJUNTOS EN KEYHUB

> **Aplica a:** Todos los agentes QA (`@qa-visual-e2e`, `@qa-smoke-tester`, `@qa-code-reviewer`), desarrolladores y testers al ejecutar validaciones sobre Historias, Casos de Prueba (`TEST_CASE`) o Defectos (`BUG`).

---

### 1. 📸 Captura Sistemática de Evidencia Visual
Todo ciclo de testing visual o funcional E2E debe registrar capturas de pantalla de los estados finales evaluados:
- **Pruebas Exitosas (`PASS`):** Capturar la interfaz demostrando el resultado correcto (ej. modal de éxito, transacción completada, documento renderizado correctamente, campos persistidos).
- **Pruebas Fallidas (`FAIL` / Defectos):** Capturar de inmediato el estado exacto de la pantalla donde se manifiesta la desviación (error visual, alerta en rojo, campos vacíos o rotos, consola del navegador con excepciones).

---

### 2. 📎 Adjunto Obligatorio a la Tarjeta en KeyHub
Queda estrictamente prohibido emitir veredictos de QA "en el aire" o únicamente mediante texto en el chat.
1. **Destino de la Evidencia:**
   - Si la prueba es **exitosa**: Las imágenes deben quedar adjuntas al **Caso de Prueba** (`TEST_CASE`) o Historia en KeyHub para certificar el cumplimiento de los criterios de aceptación (DoD).
   - Si la prueba **falla**: Las imágenes deben quedar adjuntas a la **tarjeta de BUG creada** (y referenciadas en el caso de prueba) como prueba visual incontestable para que el desarrollador reproduzca y solucione el problema.
2. **Registro en Base de Datos KeyHub:**
   - La imagen debe guardarse en el almacenamiento de adjuntos (`uploads/attachments/`) y registrarse formalmente en la tabla `card_attachments`:
     ```sql
     INSERT INTO card_attachments (id, card_id, user_id, filename, file_url, file_size, sync_source, created_at)
     VALUES ('att_xxxx', :card_id, :user_id, :filename, :file_url, :file_size, 'QA_EVIDENCE', datetime('now'));
     ```
   - Alternativamente, consumiendo el endpoint API de subida de adjuntos: `POST api/?action=upload_card_attachment`.

---

### 3. 📝 Trazabilidad en Comentarios de la Tarjeta (`card_comments`)
Cada ejecución de prueba con evidencia visual debe registrar un comentario formal en la tarjeta:
- **Estructura del Comentario:**
  - **Dictamen:** `✅ QA VISUAL PASS` o `❌ QA VISUAL FAIL`.
  - **Ambiente Evaluado:** QA (`https://qa...`) o DEV.
  - **Hallazgos Clave:** Resumen conciso de lo observado.
  - **Lista de Evidencias:** Nombre de los archivos subidos a la pestaña de **Archivos Adjuntos** (ej. `01_exito_visor_pdf.png`).

---

### 4. 🔄 Sincronización Universal y Distribución a Nuevos Desarrolladores
Esta directriz forma parte del estándar centralizado de Keystone Advisors y se distribuye automáticamente desde el **Keystone Gateway MCP** (`https://gateway.keystoneadvisors.cl`) hacia todos los repositorios locales y entornos de IDE mediante `bootstrap_workspace`.
