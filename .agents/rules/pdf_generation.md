
# 📄 REGLA PERMANENTE: ESTÁNDAR MAESTRO DE GENERACIÓN DE DOCUMENTOS PDF (FPDF / TCPDF)

> Aplica a cualquier desarrollo o modificación de generadores de PDF en todos los proyectos de Keystone Advisors.

### 1. 📏 Geometría y Límites Absolutos de Página Letter
- **Dimensiones Letter Estándar**: Ancho: `215.9 mm`, Alto: `279.4 mm`.
- **Márgenes Obligatorios**: Izquierdo: `16 mm`, Derecho: `16 mm` (Ancho imprimible útil = `183.9 mm`, redondear a `184 mm` o `183 mm` exactos).
- **Margen Superior**: `14 mm`. **Margen Inferior**: `16 mm`. Altura útil máxima = `249 mm`.
- **Suma Estricta de Columnas**: En cualquier fila o grilla de tarjetas/columnas, `Suma(anchos_celdas + gaps) <= 184 mm`. Prohibido superar este ancho porque la última columna o tarjeta saldrá cortada por el margen derecho.

### 2. 🔠 Blindaje de Caracteres y Emojis (Cero Signos de Interrogación "?")
- FPDF estándar utiliza codificación `windows-1252` o `ISO-8859-1`.
- **Prohibido enviar Emojis UTF-8 directamente a FPDF**: Emojis como `🛡️`, `🚨`, `🟢`, `✓`, `📋`, `🏢`, `⚙️`, etc., se convierten en signos de interrogación feos (`?`) en el PDF.
- **Sustitución Obligatoria por Prefijos Tipográficos**:
  - Usar etiquetas de texto limpio: `[DICTAMEN ZERO-TRUST]`, `[ALERTA BEC]`, `[TRAFICO VERIFICADO]`, `[AMENAZAS DETECTADAS]`, `[VIGENCIA]`.
  - Reemplazar símbolos por caracteres ASCII compatibles: `[OK]`, `[FAIL]`, `[!]`, `(*)`, `#`.
  - Para `c()` helper: convertir rigurosamente `iconv('UTF-8', 'windows-1252//TRANSLIT', $str)` o usar `utf8_decode($str)`.

### 3. 🎯 Coordenadas Cartesianas Relativas vs Absolutas (Cero Solapamientos)
- **Prohibición de `Rect()` desfasado**: Toda tarjeta dibujada con `$this->Rect(x, y, w, h)` debe calcular su posición `y` de forma explícita y sincronizada con el cursor del texto (`$this->GetY()`).
- **Tarjeta o Grilla Multicolumna**:
  - Si se dibujan varias tarjetas en la misma fila (KPIs), calcular el `$topY = $this->GetY()`.
  - En cada columna `i`, posicionar explícitamente `$this->SetXY($x, $topY)`.
  - Al terminar la fila completa de tarjetas, actualizar obligatoriamente el cursor general con `$this->SetY($topY + $cardHeight + $gapPosterior)`.
  - Prohibido hacer `$this->GetY() - 12` o cálculos mágicos que arrastran errores acumulados.
- **Celdas de Metadatos (Etiqueta + Valor)**:
  - Asignar anchos fijos suficientes para que la etiqueta no monte el valor:
    - Etiqueta: `Cell(34, 4.5, 'EMPRESA CLIENTE:', 0, 0)` -> Valor: `Cell(56, 4.5, $valor, 0, 1)`.
    - Nunca usar un ancho de etiqueta menor al tamaño del texto en puntos.

### 4. 📐 Control de Altura y Paginación Estricta (1 Sola Página Ejecutiva)
- Para dosieres de 1 sola página:
  - Establecer `$this->SetAutoPageBreak(false);`.
  - Distribuir verticalmente las secciones con presupuesto de altura medido:
    - Encabezado: `0 mm` a `22 mm`.
    - Título + Bajada: `24 mm` a `36 mm`.
    - Metadatos (Empresa / Folio): `37 mm` a `60 mm` (alto 23 mm).
    - Grilla 4 KPIs: `63 mm` a `83 mm` (alto 20 mm).
    - Dictamen Conformidad: `86 mm` a `104 mm` (alto 18 mm).
    - Tabla Amenazas / Spoofing: `108 mm` a `148 mm`.
    - Tabla Tráfico Legítimo: `152 mm` a `192 mm`.
    - Sello Criptográfico SHA-256: `196 mm` a `218 mm`.
    - Footer Corporativo: `260 mm` a `270 mm`.
- **Verificación Visual Obligatoria**: Antes de dar por aprobado un PDF, renderizar y verificar visualmente que no existan traslapes, signos `?` ni desbordes.
