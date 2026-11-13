# 🌐 REGLA GLOBAL: SECUENCIA Y CORRELATIVO ÚNICO INSTITUCIONAL (KEYSTONE ADVISORS)

> Aplica obligatoriamente a TODOS los agentes, proyectos, clientes y repositorios conectados al Gateway Central de Keystone (Sin Notario, KeyHub, Banco Vimenca, Banco Santa Cruz, Surges, Sitio Web, etc.).

---

### 1. 🔢 Secuencia Global Centralizada Obligatoria
- **Prohibición de Correlativos Aislados por Proyecto:** Queda estrictamente PROHIBIDO que un proyecto o repositorio mantenga numeraciones correlativas paralelas o aisladas. Toda solución, feature, fix o paquete de entrega debe asignarse y trazarse utilizando la **única secuencia global de correlativos de Keystone Advisors** (`SOL-XXXX`).
- **Fuente Oficial de Verdad:** Antes de proponer, iniciar o nombrar cualquier cambio, el agente debe verificar el último número secuencial global en la consola de Gobernanza de KeyHub o en el archivo centralizado `Keystone Advisors/KeyHub/CONTROL_CORRELATIVOS.md`.
- **Incremento Secuencial Atómico:** Se debe tomar siempre el consecutivo inmediato global (ejemplo: si el último registrado en la organización es `SOL-0247`, el siguiente desarrollo en cualquier proyecto debe ser `SOL-0248`).

---

### 2. 📝 Bitácora Centralizada y Registro Multi-Repositorio
- **Bitácora de Producción Centralizada:** La bitácora maestra de la organización reside en `Keystone Advisors/KeyHub/BITACORA_PRODUCCION.md`. Todo paso o despliegue a producción de cualquier proyecto debe quedar asentado obligatoriamente en dicha bitácora centralizada, además de la bitácora local del proyecto satélite si existiese.
- **Doble Asiento en Control de Correlativos:**
  1. Se asienta la solución con el correlativo global (`SOL-XXXX`) en el archivo maestro `Keystone Advisors/KeyHub/CONTROL_CORRELATIVOS.md`.
  2. Se asienta también en el `CONTROL_CORRELATIVOS.md` y `BITACORA_PRODUCCION.md` del repositorio local del proyecto.
  3. Todo commit y Pull Request debe titularse con la convención estándar institucional: `feat(SOL-XXXX): ...` o `fix(SOL-XXXX): ...`.
