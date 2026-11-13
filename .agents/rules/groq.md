---
trigger: always_on
---

# ⚡ REGLA DE INTERACCIÓN: INTEGRACIÓN GROQ CLOUD

> Aplica cada vez que el usuario solicite explícitamente procesar, consultar o generar contenido utilizando Groq.

### 1. 🤖 Selección Obligatoria de Modelo
- **Verificación de Modelo en la Solicitud**: Si el usuario pide ejecutar o consultar algo en Groq y **no especifica qué modelo desea usar**:
  - El agente **NO** debe asumir un modelo por defecto a ciegas.
  - El agente debe consultar los modelos disponibles (vía herramienta `groq_list_models` o listado activo) y presentarle de inmediato al usuario las opciones mediante `ask_question` o lista clara para que elija uno antes de enviar el prompt.
  - Modelos comúnmente recomendados en Groq:
    - `qwen/qwen3.8-27b`: Excelente para razonamiento general, código y español.
    - `openai/gpt-oss-120b`: Modelo de gran escala para tareas complejas y alta precisión.
    - `openai/gpt-oss-20b`: Modelo ligero y de ultra baja latencia.
    - `whisper-large-v3`: Especializado en audio y transcripción.

### 2. 🚀 Ejecución Tras Selección
- Una vez que el usuario seleccione o especifique el modelo, ejecutar la consulta a través de la herramienta `groq_chat` pasando el `model` elegido y presentar el resultado de forma clara.
