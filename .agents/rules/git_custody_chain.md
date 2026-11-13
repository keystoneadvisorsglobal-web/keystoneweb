---
trigger: always_on
---

# 🌐 REGLA GLOBAL: CADENA DE CUSTODIA GITFLOW Y GESTIÓN AUTOMÁTICA DE CREDENCIALES

> Aplica obligatoriamente a TODOS los agentes, proyectos, repositorios y desarrolladores en Keystone Advisors.

---

### 1. 🔄 Arquitectura Mandatoria de 3 Etapas Secuenciales (GitFlow Enterprise)
Queda estrictamente prohibido realizar promociones o aperturas de Pull Request saltándose etapas. Todo flujo de código debe cumplir rigurosamente la secuencia:

```
feature/XXXX (Local) ➔ develop (DEV / Integración) ➔ qa (Calidad / UAT) ➔ main (PRODUCCIÓN)
```

1. **Etapa 1: Rama de Feature hacia `develop` (Integración DEV)**
   - Todo cambio o nueva funcionalidad nace en una rama `feature/<código-tarjeta>` a partir de `develop`.
   - **Reunión de Desarrollos**: Si hay múltiples desarrolladores o agentes trabajando en paralelo, todos los cambios **se reúnen e integran primero en `develop`**.
   - **Ambiente de Validación DEV**: Se despliega y valida en el ambiente de Desarrollo/Integración del cliente (ej. `vimenca--mergeqa` para Banco Vimenca, `bsc-dev` para Banco Santa Cruz).
   - **Quality Gate DEV**: Cobertura Apex >= 85% y 100% de tests unitarios aprobados.

2. **Etapa 2: Rama `develop` hacia `qa` (Certificación QA & UAT)**
   - Únicamente cuando la integración en `develop` compila sin errores y pasa los tests unitarios conjuntos, el `@release-manager` abre el Pull Request formal de **`develop` hacia `qa`**.
   - **Ambiente de Pruebas**: Se promueve al ambiente formal de Calidad (ej. `vimenca--calidad`).
   - **Certificación**: Los agentes de QA y usuarios clave certifican los casos de prueba y realizan la homologación UAT.
   - **Estado en KeyHub**: La tarjeta madre pasa a `READY_UAT`.

3. **Etapa 3: Rama `qa` hacia `main` (Despliegue a PRODUCCIÓN)**
   - **Prohibición Absoluta de Pases Directos a `main`**: Queda **ESTRICTAMENTE PROHIBIDO abrir Pull Requests hacia `main` desde ramas `feature/` o `develop`**.
   - Solo tras la aprobación formal y explícita del usuario/Product Owner en UAT (tarjeta en `READY_PROD`), el `@release-manager` autoriza y abre el Pull Request final de **`qa` hacia `main`**.
   - Tras el merge, se ejecuta el despliegue al entorno de Producción y se asienta el registro en `BITACORA_PRODUCCION.md`.

---

### 2. 🔑 Resolución Obligatoria de Credenciales Git vía KeyHub (Anti-Permission Denied)
- **Consulta Previa de Credenciales por Proyecto**:
  - Antes de ejecutar cualquier comando de red en Git (`git push`, `git fetch`, `git pull` o apertura de Pull Request), el agente o `@release-manager` **DEBE consultar la configuración del proyecto en KeyHub** (`projects.github_repo` y `projects.github_token`).
  - **Prohibición de Asumir SSH Genérico**: Queda estrictamente PROHIBIDO confiar a ciegas en la clave SSH global del sistema (`git@github.com:...`) para repositorios corporativos de clientes.
- **Inyección Automática del PAT de KeyHub**:
  - Si el proyecto tiene configurado `github_token` en la tabla `projects`, el agente debe configurar automáticamente el remote del repositorio local antes de cualquier interacción:
    ```bash
    git remote set-url origin https://x-access-token:<GITHUB_TOKEN>@github.com/<GITHUB_REPO>.git
    ```
  - Esto garantiza que el 100% de las operaciones se ejecuten con las credenciales institucionales autorizadas.

---

### 3. 🚫 Prohibición Estricta de Despliegues "Al Vuelo" sin Commit y Push Git
- **Trazabilidad de Código Fuente:** Queda terminantemente PROHIBIDO transferir, copiar por SCP, sincronizar por rsync o promover código hacia **QA**, **STAGING** o **PRODUCCIÓN** que no esté previamente respaldado en un **Commit Git formal** y con **Push exitoso hacia el repositorio remoto oficial en GitHub / GitLab**.
- **Auditoría de Integridad:** Un archivo en el servidor que no tenga correspondencia exacta con un commit de Git es considerado código fantasma no auditado.

---

### 4. 📝 Protocolo Mandatorio del Release Manager (@release-manager)
Antes de transferir o autorizar cualquier promoción de código:
1. **Verificación de Estado Limpio (`git status`):**
   - El árbol de trabajo debe quedar limpio (`working tree clean`).
2. **Commit Atómico Formal:**
   - Todo cambio debe incluirse en un commit que referencie el correlativo institucional:
     `feat(SOL-XXXX): descripción clara del requerimiento` o `fix(SOL-XXXX): ...`
3. **Mapeo Estricto de Ramas en Pull Requests**:
   - `feature/*` ➔ `develop` (Integración DEV)
   - `develop` ➔ `qa` (Certificación QA/UAT)
   - `qa` ➔ `main` (Paso a Producción autorizado)
4. **Declaración del Hash y PR en Cadena de Custodia:**
   - Toda notificación de despliegue a QA o Prod en la tarjeta de KeyHub (`card_comments`) o en `BITACORA_PRODUCCION.md` DEBE incluir obligatoriamente:
     - El **Hash SHA del Commit** (ej: `commit 48f09b2`).
     - El enlace directo al **Pull Request en GitHub** (ej: `https://github.com/BancoVimenca/war-room/pull/1`).

---

### 5. 🚨 Cláusula de Bloqueo Inmediato
Si un comando `git push` o llamada a la API de GitHub falla por cualquier motivo (red, credenciales, conflicto):
- **El agente debe detenerse de inmediato.**
- Queda terminantemente PROHIBIDO continuar con el despliegue o simular éxito en KeyHub si la sincronización no retornó código exitoso.
- El agente debe inspeccionar KeyHub, resolver la credencial y reintentar, o solicitar asistencia si el token ha expirado.
