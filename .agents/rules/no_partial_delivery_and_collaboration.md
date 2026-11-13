# 🌐 REGLA GLOBAL: PROHIBICIÓN ESTRICTA DE ENTREGA PARCIAL Y ORQUESTACIÓN MULTI-AGENTE

> Aplica obligatoriamente a TODOS los desarrollos, tickets (`HDU`), agentes especialistas y repositorios en Keystone Advisors.

---

### 1. 🚫 Prohibición Estricta de Entregas Parciales (DoD al 100%)
- **Cero Tareas a Medias:** Queda terminantemente PROHIBIDO mover una Historia de Usuario o ticket a `READY_QA` o dar por finalizado el desarrollo si existen componentes, criterios de aceptación (DoD) o subflujos pendientes en la especificación.
- **Validación Integral de Alcance:** Una HDU no se considera terminada hasta que:
  1. La totalidad de las capas de software requeridas (Base de datos, Backend/APIs/Apex, Frontend/LWC/UI y Reglas de Validación) estén implementadas y funcionales.
  2. Los casos de prueba unitarios e integrados cubran al menos el 85% de la lógica desarrollada sin aserciones vacías ni fallos.
  3. Los artefactos estén verificados en el ambiente de desarrollo (`DEV`) y promovidos formalmente hacia `QA` por el `@release-manager`.

---

### 2. 🤝 Convocatoria Proactiva del Escuadrón Especializado (Multi-Agent Swarm)
- **Principio de Completitud:** Si una HDU requiere competencias que exceden el dominio del agente primario asignado (ej. `@dev-salesforce` detecta requerimientos de UI/LWC avanzada, integración REST o reglas complejas de base de datos):
  - El agente **NO** debe recortar el alcance ni postergar requerimientos.
  - El agente debe **invocar y orquestar formalmente a los especialistas necesarios del escuadrón Keystone**:
    - `@dev-salesforce`: Lógica Apex, Controllers, Triggers, FieldSets, CustomMetadata y Seguridad FLS.
    - `@dev-frontend`: Componentes LWC, CSS responsivo, diseño visual y experiencia de usuario.
    - `@integrations-security`: Servicios web REST/SOAP, Named Credentials y manejo de tokens.
    - `@qa-code-reviewer`: Auditoría estricta de Quality Gates y cobertura antes de la entrega.
    - `@release-manager`: Empaquetado en `package.xml`, validación en seco (`--dry-run`) y despliegue a QA.

---

### 3. 🌿 Cadena de Custodia Git y Trazabilidad Remota
- Todo el código producido de manera colaborativa por el escuadrón debe consolidarse en la rama `feature/<card_code>-<slug>`.
- El commit final debe incorporar el identificador de la HDU y su correlativo institucional (`feat(BVWAR-XXXX): descripción [SOL-XXXX]`), verificando que el árbol de trabajo quede completamente limpio (`working tree clean`) antes de la promoción.
