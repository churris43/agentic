erDiagram
  Patient ||--o{ LabReport : "has"
  LabReport ||--o| Classification : "classified by"
  LabReport ||--o{ Finding : "contains"
  LabReport ||--o{ Citation : "references"
  LabReport ||--o| Evaluation : "has"
  LabReport ||--o| Letter : "produces"
  Finding ||--o{ ExternalDbFinding : "contains"
  Citation |o--|| Finding : "relates to"

  Patient {
    id int PK
    name string
  }

  LabReport {
    id int PK
    patient_id int FK
    lab_name string
    status enum "IN_PROGRESS|COMPLETED"
    source enum "API|FILE"
    date date
  }

  Classification {
    id int PK
    lab_report_id int FK
    inheritance_mode_agent string
    inheritance_mode_cg string
    penetrance_agent string
    penetrance_gc string
    confidence_agent string
    confidence_gc string
    access_penetrance_agent string
    access_penetrance_gc string
  }

  Finding {
    id int PK
    lab_report_id int FK
    type enum "Variant | Gene | ..."
    name string
  }

  ExternalDbFinding {
    id int PK
    finding_id int FK
    provider enum "ClinVar | ClinGen | ..."
    notes text
    findings_json json
  }

  Citation {
    id int PK
    lab_report_id int FK
    finding_id int FK
    url string
    agent_summary text
  }

  Evaluation {
    id int PK
    lab_report_id int FK
    evaluation_score_agent int
    evaluation_score_gc int
    evaluation_score_reasons_agent text
    notes text
    score integer
    approval_date datetime
    approved_by int
  }

  Letter {
    id int PK
    lab_report_id int FK
    draft text
    final text
    approval_date datetime
    approved_by int
  }
