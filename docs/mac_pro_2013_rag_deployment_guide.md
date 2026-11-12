# Mac Pro (Late 2013) RAG Deployment & Architecture Guide

## 1. Verified Hardware & Host Specifications

Based on hardware scans (`sensors-detect`, `lscpu`, and Ubuntu Server telemetry):

| Component | Specification | Operational Status |
| :--- | :--- | :--- |
| **Model** | Apple MacPro6,1 ("Trash Can") | Active / Headless Server |
| **Processor** | Intel Xeon E5-1620 v2 @ 3.70 GHz (4 Cores / 8 Threads) | ~45°C – 49°C idle |
| **Memory** | 16 GB DDR3 ECC Quad-Channel | ~6% baseline utilization |
| **Graphics** | Dual AMD FirePro D300 (2 GB GDDR5 VRAM per card) | Idle (~44°C, 300MHz core) |
| **Internal Storage** | 256 GB Apple PCIe SSD (`/` on ~98 GB partition) | ~9.3% root usage |
| **Cooling** | Single central impeller controlled via `macfanctld` / Apple SMC | Baseline ~2,000 RPM |
| **Network** | Dual Gigabit Ethernet (active interface: `enp11s0`) | Connected (LAN) |
| **Operating System** | Ubuntu 26.04 LTS (Kernel `7.0.0-38-generic x86_64`) | Clean base install |

---

## 2. Feasibility Assessment: Will a RAG Agent Work?

**Verdict:** **Yes, but under an asymmetrical/hybrid architecture.**

### The Hard Constraints
1. **GPU Limitation (Legacy GCN 1.0):**
   * The dual AMD FirePro D300s are based on the Pitcairn architecture (2012–2013).
   * **Modern AMD ROCm does not support GCN 1.0.** PyTorch, vLLM, and ExLlama will not run on these GPUs.
   * With only 2 GB of VRAM per card, modern quantized models (even 4-bit 7B models requiring ~4.5 GB to 6 GB) cannot fit into GPU memory.
2. **CPU Bounds:**
   * The Ivy Bridge-EP Xeon E5-1620 v2 supports AVX, but lacks AVX2 and AVX-512 instructions.
   * CPU inference on large models (7B+) will produce unacceptable latency (~1.5 to 3 tokens/sec).

### Where This Machine Excels
* **Vector Indexing & Retrieval:** Running Qdrant, Chroma, or PostgreSQL with `pgvector` in RAM with low latency.
* **Embedding Inference:** High throughput on lightweight embedding models (`nomic-embed-text`, `bge-small-en-v1.5`, `all-MiniLM-L6-v2`) via quantized CPU engines (Ollama / ONNX Runtime).
* **Agent Orchestration:** Hosting LangGraph, CrewAI, LlamaIndex, FastAPI endpoints, background document parsers, and caching layers.

---

## 3. Recommended RAG Architecture

```
                  ┌────────────────────────────────────────────────────────┐
                  │                 Mac Pro (Headless Host)                │
                  │                                                        │
┌──────────────┐  │  ┌─────────────────┐       ┌────────────────────────┐  │
│ Document     │──┼─►│ Ingestion &     │──────►│ Vector DB Engine       │  │
│ Uploads /    │  │  │ Chunking Worker │       │ (Qdrant / pgvector)    │  │
│ Connectors   │  │  └────────┬────────┘       └───────────┬────────────┘  │
└──────────────┘  │           │                            │               │
                  │           ▼                            │               │
                  │  ┌─────────────────┐                   │               │
                  │  │ Local Embedding │                   │               │
                  │  │ (nomic-embed)   │                   │               │
                  │  └─────────────────┘                   │ Context Chunks│
                  │                                        ▼               │
                  │                            ┌────────────────────────┐  │
                  │                            │ RAG Agent Orchestrator │  │
                  │                            │ (FastAPI / LangGraph)  │  │
                  │                            └───────────┬────────────┘  │
                  └────────────────────────────────────────┼───────────────┘
                                                           │
                        ┌──────────────────────────────────┴───────────────────┐
                        │ Generation Route Choice                              │
                        ▼                                                      ▼
           ┌────────────────────────┐                             ┌────────────────────────┐
           │ Cloud LLM API          │                             │ Local Fallback Model   │
           │ (Fast & High Accuracy) │                             │ (Offline / Zero-Cost)  │
           ├────────────────────────┤                             ├────────────────────────┤
           │ Gemini / Claude /      │                             │ Ollama CPU Execution:  │
           │ OpenAI / Groq          │                             │ Qwen2.5-1.5B /         │
           │                        │                             │ Llama-3.2-1B           │
           └────────────────────────┘                             └────────────────────────┘
```

---

## 4. Workload Tier Recommendations

### Tier 1: Hybrid Architecture (Recommended)
* **Vector Storage:** Qdrant or PostgreSQL + `pgvector` (Docker on Mac Pro).
* **Embeddings:** `nomic-embed-text` (Local Ollama running on CPU).
* **LLM Synthesis:** External API (Groq, Anthropic, Google Gemini, OpenAI).
* **Result:** Millisecond retrieval times, rapid token generation, negligible heat and power footprint.

### Tier 2: 100% Offline / Local Operation
* **Vector Storage:** Qdrant (Docker).
* **Embeddings:** `all-MiniLM-L6-v2` or `bge-small-en-v1.5` (CPU via Ollama / ONNX).
* **LLM Generation:** `qwen2.5:1.5b` or `llama3.2:1b` (4-bit quantized via CPU Ollama).
* **Performance Expectation:** ~6–12 tokens/second generation speed on the 4 Xeon cores.

---

## 5. Deployment Setup (`compose.yaml`)

Run this stack in `~/server-stack/compose.yaml` to power the database, local models, and orchestration pipeline:

```yaml
services:
  # Vector Database
  qdrant:
    image: qdrant/qdrant:latest
    container_name: qdrant
    restart: unless-stopped
    ports:
      - "6333:6333"
    volumes:
      - qdrant_data:/qdrant/storage
    environment:
      - QDRANT__SERVICE__MAX_REQUEST_SIZE_MB=32

  # CPU Embedding and Small Model Engine
  ollama:
    image: ollama/ollama:latest
    container_name: ollama
    restart: unless-stopped
    ports:
      - "11434:11434"
    volumes:
      - ollama_data:/root/.ollama
    environment:
      - OLLAMA_NUM_PARALLEL=2
      - OLLAMA_MAX_LOADED_MODELS=2

volumes:
  qdrant_data:
  ollama_data:
```

### Initial Model Download
```bash
# High-accuracy, low-overhead embedding model
docker exec -it ollama ollama pull nomic-embed-text

# Optional local small reasoning model
docker exec -it ollama ollama pull qwen2.5:1.5b
```

---

## 6. Maintenance & Performance Rules for this Machine

1. **Cap Container Logs:** Keep `/etc/docker/daemon.json` limited to `10m` log files with a max of 3 backups to preserve the 256 GB drive.
2. **Thermal Stability:** Keep `macfanctld` enabled and running to avoid heat accumulation inside the cylindrical core under heavy embedding jobs.
3. **Avoid Large Context Windows on CPU:** When using local small LLMs on CPU, cap context length at 2,048 or 4,096 tokens to avoid CPU cache bottlenecks.