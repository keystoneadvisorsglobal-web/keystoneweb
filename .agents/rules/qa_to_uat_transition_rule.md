---
trigger: always_on
---

# 🎯 REGLA GLOBAL: TRANSICIÓN A UAT Y HOMOLOGACIÓN EN AMBIENTES QA

> Aplica obligatoriamente a todos los agentes de QA, Release Managers y Desarrolladores en todos los proyectos y clientes Keystone (Salesforce, PHP, Web, WordPress, etc.).

---

### 1. 🔄 Transición Obligatoria de QA a 'Listo para UAT' (`READY_UAT`)
- **Regla Estricta de Fin de Ciclo QA**:
  - Al finalizar con éxito las pruebas y la certificación en la etapa de Control de Calidad (`IN_QA`), el agente de QA **NUNCA** debe dejar la tarjeta en limbo ni moverla a finalizada (`DONE`) de forma directa.
  - La tarjeta madre (Historia de Usuario o Requerimiento) **DEBE transicionarse invariablemente a `READY_UAT` ("Listo para UAT")** tanto en el tablero KeyHub remoto como en la réplica local.
  - Los Casos de Prueba hijos (`TEST_CASE`) aprobados pasan a `DONE`.

---

### 2. 🏢 Proyectos con Ambiente Único de Pruebas (Sin Org/Servidor UAT Dedicado)
- **Ejecución de UAT en Ambiente QA**:
  - En proyectos u organizaciones donde **NO exista un ambiente exclusivo de UAT** (User Acceptance Testing) y solo se disponga de ambiente de **QA / Calidad** (por ejemplo: `vimenca-calidad` en Banco Vimenca):
    - **Tanto las pruebas internas de QA como las pruebas de certificación UAT de negocio/usuario final se realizan y validan estrictamente sobre el ambiente QA (`vimenca-calidad`)**.
    - Queda estrictamente prohibido inventar o requerir un ambiente UAT inexistente.
    - La tarjeta en `READY_UAT` habilita la revisión formal de los usuarios clave o product owners directamente en el ambiente QA disponible.

---

### 3. 📝 Bitácora de Aprobación y Evidencia
- Todo pase a `READY_UAT` debe registrar un comentario explícito en KeyHub detallando:
  1. Resultados del set de pruebas QA superadas.
  2. Ambiente objetivo donde se debe ejecutar la prueba UAT (ej. `vimenca-calidad`).
  3. Criterios y datos de prueba recomendados para que el usuario de negocio o PO realice la aceptación.

