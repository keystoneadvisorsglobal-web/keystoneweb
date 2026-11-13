---
trigger: always_on
---

# 🎓 REGLAS DE WORKSPACE: SURGES CAPACITACIONES

> Aplica cuando se trabaje en proyectos, código o archivos bajo `Surges Capacitaciones/`.

### 1. ⚠️ Operación en Entornos Productivos Directos (Moodle & WordPress)
- **Contexto Operativo**: Los entornos de este cliente (Moodle `cursos.surgescapacitaciones.cl` y WordPress) son **ambientes productivos directos sin etapas previas ni sandboxes**.
- **Respaldo Local Obligatorio**: Antes de modificar, reemplazar o actualizar cualquier archivo en caliente, se debe generar un respaldo con timestamp (ej. `archivo.php.bak_YYYYMMDD_HHMMSS`).
- **Verificación Sintáctica Previa (PHP Lint)**: Queda estrictamente prohibido subir o aplicar cambios a archivos PHP sin haber ejecutado previamente `php -l <archivo.php>` para garantizar cero errores fatales o pantallas blancas (WSOD).
- **Prohibición de Alteración del Core**: No modificar archivos base o del núcleo de Moodle ni WordPress. Todas las personalizaciones deben residir exclusivamente en el plugin propio o en el tema hijo.

### 2. 🔌 Componentes Propietarios de Keystone Advisors
- **Plugin Sence (`block_sence_connector`)**:
  - Controlado por el repositorio Git oficial de Keystone: `moodle-block_sence_connector`.
  - Todo cambio funcional debe comprometerse en su rama/commit correspondiente antes de desplegarse en la instancia de Moodle.
  - Cuidar la persistencia de configuraciones y tokens de conexión al servicio de Sence para no interrumpir la sincronización de alumnos ni asistencia.
- **Tema Personalizado (`theme_keystone`)**:
  - Toda modificación de estilos o templates (Mustache/CSS) debe ser compatible con la versión activa de Moodle.
  - Asegurar la purga selectiva de caché de Moodle únicamente cuando sea estrictamente necesario para reflejar los cambios visuales, evitando degradar el rendimiento del servidor en producción.

### 3. 🌐 Próxima Conexión WordPress
- Mantener la carpeta de WordPress desacoplada hasta que se establezca la conexión formal.
- No asumir credenciales ni accesos a base de datos de WordPress sin validación previa.

### 4. 📦 Trazabilidad y Correlativo (`SOL-XXXX`)
- Toda intervención correctiva, cambio visual o actualización del plugin Sence debe registrarse con su correlativo `SOL-XXXX` en el control de soluciones del cliente, dejando constancia de los archivos tocados y el commit asociado en el Git de Keystone.
