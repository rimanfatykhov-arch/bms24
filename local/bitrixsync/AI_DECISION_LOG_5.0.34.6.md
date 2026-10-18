# AI Decision Log — v5.0.34.6

Decision: service instances used by a control page must be initialized once
before action handling and rendering. Repeated anonymous construction was
replaced by one explicit CorporateRoleSchemaService instance.
