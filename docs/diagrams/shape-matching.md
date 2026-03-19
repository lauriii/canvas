```mermaid
---
title: How Canvas suggests structured data that fits into components' props' shapes
---
graph TB
subgraph Suggester["🎯 Suggester — subjective: curated, not everything, most likely first"]
PS["PropSourceSuggester<br/>(Filter & order matches<br/>based on context)"]
end

    subgraph Matchers["🔍 Matchers — objective: _anything_ that can match, even if odd)"]
        EFM["EntityFieldPropSourceMatcher<br/>(match entity fields<br/>to arbitrary prop shapes)"]
        HPM["HostEntityUrlPropSourceMatcher<br/>(match host entity URLs<br/>to URI prop shapes)"]
        APM["AdaptedPropSourceMatcher<br/>(match adapters<br/>to arbitrary prop shapes)<br>⚠️ Not yet in use!"]
    end

    subgraph Inputs["📥 Inputs"]
        CMD["Component Metadata<br/>(SDC's JSON schema or equivalent)<br /><br />1. required vs optional<br />2. shape (normalized schema)"]
        ETB["Entity Type + Bundle<br/>(host context)"]
    end

    subgraph Outputs["📤 Outputs"]
        EFS["EntityFieldPropSources<br/>(suggested fields & field properties)"]
        HEU["HostEntityUrlPropSources<br/>(suggested host entity URLs)"]
        ADP["AdaptedPropSources<br/>(suggested adapters)"]
    end

    PS -->|find ALL matches| EFM
    PS -->|find ALL matches| HPM
    PS -->|find ALL matches| APM

    CMD --> PS
    ETB --> PS

    EFM -->|match 0…n| EFS
    HPM -->|match 0…n| HEU
    APM -->|match 0…n| ADP

    EFS -->|1. filter: keep only relevant — subjective!</code><br>2. order: match form order<br>3. compute label| PS
    HEU -->|1. filter: none</code><br>2. order: none<br>3. compute label| PS
    ADP -->|1. filter: none</code><br>2. order: none<br>3. compute label| PS

    PS -->|suggest<br/>filtered & ordered| Outputs

    PS -->|server → UI | HumanSuggestions

    HumanSuggestions(("🧑‍💻 Human-readable suggestions in sensible order"))

    Outputs ~~~ HumanSuggestions

    classDef suggester fill:#e1f5ff,stroke:#01579b,stroke-width:2px
    classDef matcher fill:#f3e5f5,stroke:#4a148c,stroke-width:2px
    classDef input fill:#e8f5e9,stroke:#1b5e20,stroke-width:2px
    classDef output fill:#fff3e0,stroke:#e65100,stroke-width:2px

    class PS suggester
    class EFM,HPM,APM matcher
    class CMD,ETB,IR,IS input
    class EFS,HEU,ADP output
