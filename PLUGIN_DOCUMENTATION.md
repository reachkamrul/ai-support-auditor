# Support Ops & AI Auditor Plugin - Complete Documentation

**Version:** 10.0-OOP (Enterprise Edition)  
**Last Updated:** 2024

---

## Table of Contents

1. [Plugin Overview](#plugin-overview)
2. [Architecture](#architecture)
3. [Database Schema](#database-schema)
4. [API Endpoints](#api-endpoints)
5. [Services](#services)
6. [Admin Interface](#admin-interface)
7. [N8N Integration](#n8n-integration)
8. [Workflow Examples](#workflow-examples)
9. [Security](#security)
10. [Dependencies](#dependencies)

---

## Plugin Overview

### Core Purpose

A WordPress plugin that integrates with **FluentSupport** and **N8N** to:
- Automatically audit support tickets using AI
- Track agent performance and shift compliance
- Analyze support topics and knowledge gaps
- Provide comprehensive analytics and reporting

### Key Features

1. **AI-Powered Ticket Auditing** - Automatic evaluation of support tickets with scoring
2. **Shift Compliance Tracking** - Validates if agents responded during their shifts
3. **Agent Performance Analytics** - Individual dashboards, trends, comparisons
4. **Topic Detection & Analytics** - Identifies recurring issues and FAQ candidates
5. **Knowledge Gap Detection** - Flags areas needing documentation

---

## Architecture

### File Structure

```
ai-support-auditor/
├── ai-support-auditor.php          # Main plugin file (bootstrap)
├── run-backfill.php                 # Standalone backfill script
├── credentials.txt                  # Credentials (DO NOT COMMIT)
├── includes/
│   ├── Plugin.php                  # Main plugin class (Singleton)
│   ├── Database/
│   │   └── Manager.php             # Database operations & migrations
│   ├── Admin/
│   │   ├── Manager.php             # Admin interface manager
│   │   ├── Assets.php             # CSS/JS enqueuing
│   │   └── Pages/                 # Admin page renderers
│   │       ├── Dashboard.php
│   │       ├── Agents.php
│   │       ├── AgentPerformance.php
│   │       ├── Analytics.php
│   │       ├── Calendar.php
│   │       ├── Settings.php
│   │       ├── SystemMessage.php
│   │       ├── ApiConfig.php
│   │       └── Backfill.php
│   ├── API/
│   │   ├── Manager.php             # REST API route registration
│   │   ├── Middleware/
│   │   │   └── TokenVerification.php
│   │   └── Endpoints/
│   │       ├── AuditEndpoint.php
│   │       ├── ShiftEndpoint.php
│   │       ├── AgentEndpoint.php
│   │       └── SystemMessageEndpoint.php
│   ├── AJAX/
│   │   ├── Manager.php             # AJAX handler registration
│   │   └── Handlers/
│   │       ├── AuditHandler.php
│   │       ├── ShiftHandler.php
│   │       └── TestHandler.php
│   └── Services/
│       ├── TranscriptBuilder.php   # Builds ticket transcripts
│       ├── ShiftProcessor.php      # Processes shift schedules
│       ├── ShiftChecker.php        # Validates shift compliance
│       ├── EvaluationSaver.php     # Saves agent evaluations
│       ├── ContributionSaver.php   # Saves agent contributions
│       ├── ProblemContextSaver.php # Saves problem contexts
│       ├── TopicStatsUpdater.php   # Updates topic statistics
│       └── BackfillService.php     # Backfills historical data
```

### Core Components

#### 1. Plugin.php (Main Class)
- **Pattern:** Singleton
- **Responsibilities:**
  - Initializes all components
  - Registers WordPress hooks
  - Manages component lifecycle

#### 2. Database Manager
- **File:** `includes/Database/Manager.php`
- **Responsibilities:**
  - Creates and migrates database tables
  - Auto-repair functionality
  - Table name helper methods
- **Table Prefix:** `yfao_ais_*` (configurable via WordPress)

#### 3. Admin Manager
- **File:** `includes/Admin/Manager.php`
- **Responsibilities:**
  - Registers WordPress admin menu
  - Manages admin page rendering
  - Handles CSV exports

#### 4. API Manager
- **File:** `includes/API/Manager.php`
- **Responsibilities:**
  - Registers REST API routes
  - Manages endpoint handlers
  - Token verification middleware

#### 5. AJAX Manager
- **File:** `includes/AJAX/Manager.php`
- **Responsibilities:**
  - Handles WordPress AJAX requests
  - Routes to appropriate handlers

---

## Database Schema

### Tables Overview

All tables use prefix: `{wpdb->prefix}ais_` (typically `yfao_ais_`)

### 1. `ais_audits` - Main Audit Results

**Purpose:** Stores ticket audit status, scores, and AI responses

**Columns:**
- `id` (bigint) - Primary key
- `ticket_id` (varchar(50)) - FluentSupport ticket ID
- `status` (varchar(20)) - `pending`, `success`, `failed`
- `overall_score` (int) - Overall audit score (0-100)
- `error_message` (text) - Error details if failed
- `raw_json` (longtext) - Raw ticket transcript JSON
- `audit_response` (longtext) - Full AI audit response JSON
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `ticket_id` (`ticket_id`)
- KEY `status` (`status`)

### 2. `ais_agent_shifts` - Agent Shift Schedules

**Purpose:** Stores agent shift assignments

**Columns:**
- `id` (bigint) - Primary key
- `agent_email` (varchar(100)) - Agent email
- `shift_def_id` (int) - Reference to shift definition
- `shift_start` (datetime) - Shift start time
- `shift_end` (datetime) - Shift end time
- `shift_type` (varchar(50)) - Shift type name
- `shift_color` (varchar(20)) - Display color
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `agent_time` (`agent_email`, `shift_start`)

### 3. `ais_agents` - Agent Master Data

**Purpose:** Stores agent information synced from FluentSupport

**Columns:**
- `id` (bigint) - Primary key
- `first_name` (varchar(100))
- `last_name` (varchar(100))
- `email` (varchar(100)) - UNIQUE
- `title` (varchar(100)) - Job title
- `fluent_agent_id` (int) - FluentSupport agent ID
- `avatar_url` (varchar(500))
- `is_active` (tinyint(1)) - Active status
- `last_synced` (datetime) - Last sync timestamp
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `email` (`email`)
- KEY `fluent_id` (`fluent_agent_id`)

### 4. `ais_shift_definitions` - Shift Templates

**Purpose:** Predefined shift types

**Columns:**
- `id` (bigint) - Primary key
- `name` (varchar(50)) - Shift name
- `start_time` (time) - Start time
- `end_time` (time) - End time
- `color` (varchar(20)) - Display color

**Pre-seeded Data:**
- Day Shift: 09:00 - 18:00 (#dcfce7)
- Evening Shift: 15:00 - 00:00 (#f1f5f9)
- Deal Shift: 19:00 - 04:00 (#fef2f2)

### 5. `ais_topic_stats` - Topic Analytics

**Purpose:** Tracks recurring support topics

**Columns:**
- `id` (bigint) - Primary key
- `topic_slug` (varchar(100)) - UNIQUE slug
- `topic_label` (varchar(200)) - Human-readable label
- `category` (varchar(50)) - Topic category
- `ticket_count` (int) - Number of occurrences
- `first_seen` (date) - First occurrence
- `last_seen` (date) - Last occurrence
- `is_faq_candidate` (tinyint(1)) - Flag if >= 10 occurrences
- `is_doc_update_needed` (tinyint(1)) - Documentation needed flag

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `topic_slug` (`topic_slug`)
- KEY `ticket_count` (`ticket_count`)

### 6. `ais_agent_contributions` - Agent Contribution Metrics

**Purpose:** Stores agent contribution data per ticket

**Columns:**
- `id` (bigint) - Primary key
- `ticket_id` (varchar(50)) - Ticket ID
- `agent_email` (varchar(100)) - Agent email
- `contribution_percentage` (int) - Contribution % (0-100)
- `reply_count` (int) - Number of replies
- `quality_score` (int) - Quality score
- `reasoning` (text) - AI reasoning (JSON)
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `ticket_id` (`ticket_id`)
- KEY `agent_email` (`agent_email`)

### 7. `ais_agent_evaluations` - Detailed Agent Performance

**Purpose:** Comprehensive agent evaluation per ticket

**Columns:**
- `id` (bigint) - Primary key
- `ticket_id` (varchar(50)) - Ticket ID
- `agent_email` (varchar(100)) - Agent email
- `agent_name` (varchar(200)) - Agent full name
- `timing_score` (int) - Timing score (0-100)
- `resolution_score` (int) - Resolution score (0-100)
- `communication_score` (int) - Communication score (0-100)
- `overall_agent_score` (int) - Overall score (0-100)
- `contribution_percentage` (int) - Contribution % (0-100)
- `reply_count` (int) - Number of replies
- `reasoning` (text) - AI reasoning text
- `shift_compliance` (longtext) - Shift compliance data (JSON)
- `response_breakdown` (longtext) - Response breakdown (JSON)
- `key_achievements` (longtext) - Achievements (JSON)
- `areas_for_improvement` (longtext) - Improvement areas (JSON)
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `ticket_id` (`ticket_id`)
- KEY `agent_email` (`agent_email`)
- KEY `created_at` (`created_at`)

### 8. `ais_problem_contexts` - Problem Categorization

**Purpose:** Stores problem context from AI analysis

**Columns:**
- `id` (bigint) - Primary key
- `ticket_id` (varchar(50)) - Ticket ID
- `problem_slug` (varchar(100)) - Problem slug
- `issue_description` (text) - Issue description
- `category` (varchar(50)) - Category
- `severity` (varchar(20)) - Severity level
- `responsible_agent` (varchar(100)) - Responsible agent email
- `agent_marking` (int) - Agent marking score
- `reasoning` (text) - AI reasoning
- `created_at` (datetime) - Timestamp

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `ticket_id` (`ticket_id`)
- KEY `problem_slug` (`problem_slug`)
- KEY `category` (`category`)

### 9. `ais_doc_central_meta` - Documentation Tracking

**Purpose:** Tracks documentation sources and status

**Columns:**
- `id` (bigint) - Primary key
- `product_name` (varchar(100)) - Product name
- `doc_url` (varchar(500)) - Documentation URL (UNIQUE)
- `pinecone_namespace` (varchar(100)) - Pinecone namespace
- `last_scraped` (datetime) - Last scrape timestamp
- `chunk_count` (int) - Number of chunks
- `status` (varchar(20)) - Status (active/inactive)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `doc_url` (`doc_url`)

---

## API Endpoints

### Base URL
```
/wp-json/ai-audit/v1/
```

### Authentication
All endpoints (except admin endpoints) require `X-Audit-Token` header:
```
X-Audit-Token: {token}
```

Token is stored in WordPress option: `ai_audit_secret_token`

### Endpoints

#### Audit Endpoints

##### `POST /save-result`
**Purpose:** N8N saves audit results after AI processing

**Request Body:**
```json
{
  "ticket_id": "123",
  "status": "success",
  "score": 85,
  "raw_json": "...",
  "audit_response": {
    "agent_evaluations": [...],
    "agent_contributions": [...],
    "problem_contexts": [...]
  }
}
```

**Response:**
```json
{
  "status": "saved",
  "ticket_id": "123",
  "audit_id": 456
}
```

##### `GET /get-pending`
**Purpose:** N8N fetches pending audits to process

**Query Parameters:**
- `limit` (int, default: 10) - Number of tickets to fetch

**Response:**
```json
[
  {
    "ticket_id": "123",
    "retry": false
  }
]
```

#### Shift Endpoints

##### `GET /get-shift-context`
**Purpose:** Get shift context for a ticket

**Query Parameters:**
- `ticket_id` (required) - Ticket ID

**Response:**
```json
{
  "ticket_id": "123",
  "created_at": "2024-01-01 10:00:00",
  "shift_context": "Feature in development - Phase 2"
}
```

##### `POST /check-shift`
**Purpose:** Check if agent was on shift at specific datetime

**Request Body:**
```json
{
  "agent_email": "agent@example.com",
  "datetime": "2024-01-01 14:30:00"
}
```

**Response:**
```json
{
  "agent_email": "agent@example.com",
  "datetime": "2024-01-01 14:30:00",
  "was_on_shift": true,
  "is_weekend": false,
  "day_of_week": "Monday",
  "shift_info": {
    "shift_type": "Day Shift",
    "shift_start": "2024-01-01 09:00:00",
    "shift_end": "2024-01-01 18:00:00"
  }
}
```

##### `POST /check-shifts-batch`
**Purpose:** Batch check multiple agent shifts

**Request Body:**
```json
{
  "checks": [
    {
      "agent_email": "agent1@example.com",
      "datetime": "2024-01-01 14:30:00"
    },
    {
      "agent_email": "agent2@example.com",
      "datetime": "2024-01-01 15:00:00"
    }
  ]
}
```

**Response:**
```json
{
  "results": [...],
  "total_checked": 2
}
```

#### System Message Endpoints

##### `GET /get-system-message`
**Purpose:** Get AI system message (prompt)

**Response:**
```json
{
  "system_message": "...",
  "updated_at": "2024-01-01 10:00:00",
  "timestamp": "2024-01-01 10:00:00"
}
```

##### `POST /save-system-message`
**Purpose:** Save AI system message

**Request Body:**
```json
{
  "system_message": "..."
}
```

**Response:**
```json
{
  "status": "saved",
  "updated_at": "2024-01-01 10:00:00"
}
```

##### `POST /test-system-message`
**Purpose:** Test system message with a ticket

**Request Body:**
```json
{
  "ticket_id": 123
}
```

**Response:**
```json
{
  "ticket_id": 123,
  "system_message_prepared": "...",
  "transcript_length": 5000,
  "note": "This is a preview. Full AI testing requires API integration."
}
```

#### Agent Endpoints (Admin Only)

##### `GET /agents`
**Purpose:** Get all agents

**Response:**
```json
[
  {
    "email": "agent@example.com",
    "name": "John Doe",
    ...
  }
]
```

##### `GET /agents/{email}`
**Purpose:** Get agent details

**Response:**
```json
{
  "email": "agent@example.com",
  "name": "John Doe",
  "stats": {...}
}
```

##### `GET /agents/{email}/trend`
**Purpose:** Get agent performance trends

**Query Parameters:**
- `days` (int, default: 30) - Number of days

**Response:**
```json
{
  "trends": [...]
}
```

##### `GET /agents/{email}/compare`
**Purpose:** Compare agent with others

**Response:**
```json
{
  "agent": {...},
  "comparison": {...}
}
```

---

## Services

### 1. TranscriptBuilder

**File:** `includes/Services/TranscriptBuilder.php`

**Purpose:** Builds ticket transcripts from FluentSupport for AI processing

**Key Methods:**
- `build($ticket_id)` - Builds transcript for a ticket
- `format_ticket($ticket)` - Formats ticket object
- `build_timeline($ticket)` - Builds conversation timeline
- `identify_actor($response, $ticket)` - Identifies agent vs customer

**Output Format:**
```
### TICKET METADATA
ID: 123
TITLE: Support Request
STATUS: open
CUSTOMER: John Doe
CONTENT: Initial ticket content

### TIMELINE
[2024-01-01 10:00:00] 👤 AGENT (Jane):
"Response content"

[2024-01-01 10:30:00] 👤 CUSTOMER (John):
"Customer reply"
```

### 2. ShiftProcessor

**File:** `includes/Services/ShiftProcessor.php`

**Purpose:** Processes shift schedule creation/updates

**Key Methods:**
- `process($post_data)` - Processes shift data from admin form

**Features:**
- Handles date ranges
- Supports overnight shifts
- Deletes existing shifts before inserting new ones

### 3. ShiftChecker

**File:** `includes/Services/ShiftChecker.php`

**Purpose:** Validates if agent was on shift at specific time

**Key Methods:**
- `check($agent_email, $datetime)` - Check single agent
- `check_batch($checks)` - Batch check multiple agents

**Returns:**
- `was_on_shift` (bool)
- `is_weekend` (bool)
- `day_of_week` (string)
- `shift_info` (array|null)

### 4. EvaluationSaver

**File:** `includes/Services/EvaluationSaver.php`

**Purpose:** Saves agent evaluations from AI audit response

**Key Methods:**
- `save($ticket_id, $audit_data)` - Saves evaluations

**Process:**
1. Deletes old evaluations for ticket
2. Parses `agent_evaluations` from audit data
3. Inserts new evaluations with all scores and metadata

### 5. ContributionSaver

**File:** `includes/Services/ContributionSaver.php`

**Purpose:** Saves agent contribution data

**Key Methods:**
- `save($ticket_id, $audit_data)` - Saves contributions

**Supports:**
- New format: `agent_evaluations`
- Legacy format: `agent_contributions`

### 6. ProblemContextSaver

**File:** `includes/Services/ProblemContextSaver.php`

**Purpose:** Saves problem context data from AI analysis

**Key Methods:**
- `save($ticket_id, $audit_data)` - Saves problem contexts

### 7. TopicStatsUpdater

**File:** `includes/Services/TopicStatsUpdater.php`

**Purpose:** Updates topic statistics from audit data

**Key Methods:**
- `update($audit_data)` - Updates topic stats

**Logic:**
- Creates new topic if doesn't exist
- Increments `ticket_count` if exists
- Sets `is_faq_candidate = 1` if count >= 10
- Updates `last_seen` timestamp

### 8. BackfillService

**File:** `includes/Services/BackfillService.php`

**Purpose:** Backfills historical data

**Key Methods:**
- `backfill_agent_evaluations($verbose)` - Backfills evaluations from old audits
- `get_stats()` - Gets backfill statistics

**Usage:**
```bash
php run-backfill.php
```

---

## Admin Interface

### Menu Structure

**Main Menu:** "Support Ops & AI Auditor" (slug: `ai-ops`)

**Submenus:**
1. **Dashboard** (`ai-ops`) - Main dashboard (default tab: Calendar)
2. **Agent Performance** (`ai-ops-agents`) - Agent performance dashboard

### Tabs (in Dashboard)

1. **Calendar** - Shift calendar management
2. **Shift Settings** - Configure shift definitions
3. **AI Audits** - View audit results
4. **Analytics** - Topic and performance analytics
5. **Agents** - Agent management
6. **System Message** - Configure AI prompt
7. **API Config** - API configuration

### Admin Pages

#### Dashboard (`includes/Admin/Pages/Dashboard.php`)
- Lists all audits
- Filter by status
- Search tickets
- View audit details in modal
- Force audit button

#### Agent Performance (`includes/Admin/Pages/AgentPerformance.php`)
- List view: All agents with stats
- Detail view: Individual agent performance
- Export to CSV
- Trend charts
- Comparison tools

#### Calendar (`includes/Admin/Pages/Calendar.php`)
- Visual shift calendar
- Add/edit shifts
- Delete shifts
- Date range selection

#### Settings (`includes/Admin/Pages/Settings.php`)
- Shift definition management
- Add/edit/delete shift types

#### System Message (`includes/Admin/Pages/SystemMessage.php`)
- Edit AI system message (prompt)
- Test with sample ticket
- Preview prepared message

#### API Config (`includes/Admin/Pages/ApiConfig.php`)
- View API token
- Regenerate token
- API endpoint documentation

#### Analytics (`includes/Admin/Pages/Analytics.php`)
- Topic statistics
- FAQ candidates
- Documentation needs
- Trend analysis

---

## N8N Integration

### Webhook Configuration

**URL:** `https://team.junior.ninja/webhook/force-audit`

**N8N API Key:** Stored in credentials.txt

### Integration Flow

1. **Plugin → N8N (Trigger Audit)**
   - Plugin creates audit record with `status='pending'`
   - Plugin sends POST to N8N webhook with:
     ```json
     {
       "ticket_id": "123",
       "force": true,
       "raw_json": "{...transcript...}"
     }
     ```

2. **N8N Processing**
   - N8N receives webhook
   - Fetches system message from WordPress API
   - Processes ticket transcript with AI
   - Generates audit response

3. **N8N → Plugin (Save Results)**
   - N8N calls `POST /save-result` with:
     ```json
     {
       "ticket_id": "123",
       "status": "success",
       "score": 85,
       "audit_response": {
         "agent_evaluations": [...],
         "agent_contributions": [...],
         "problem_contexts": [...]
       }
     }
     ```

4. **Plugin Processing**
   - Updates audit record
   - Saves agent evaluations
   - Saves contributions
   - Saves problem contexts
   - Updates topic stats

### Polling for Pending Audits

N8N can poll for pending audits:
```
GET /wp-json/ai-audit/v1/get-pending?limit=10
```

Returns list of tickets needing audit.

---

## Workflow Examples

### Example 1: New Ticket Audit

1. New ticket created in FluentSupport (ID: 123)
2. Admin clicks "Force Audit" or scheduled batch runs
3. `AuditHandler::force_audit()` called
4. `TranscriptBuilder::build(123)` fetches ticket from FluentSupport
5. Transcript formatted and stored in `ais_audits.raw_json`
6. POST sent to N8N webhook (non-blocking)
7. N8N processes with AI
8. N8N calls `POST /save-result`
9. `AuditEndpoint::save_result()` processes response
10. Services save:
    - `EvaluationSaver` → `ais_agent_evaluations`
    - `ContributionSaver` → `ais_agent_contributions`
    - `ProblemContextSaver` → `ais_problem_contexts`
    - `TopicStatsUpdater` → `ais_topic_stats`
11. Admin views results in dashboard

### Example 2: Shift Compliance Check

1. AI audit response includes agent responses with timestamps
2. For each agent response:
   - Extract `agent_email` and `datetime`
   - Call `ShiftChecker::check($email, $datetime)`
   - Query `ais_agent_shifts` for matching shift
   - Calculate compliance score
3. Store compliance data in `ais_agent_evaluations.shift_compliance` (JSON)

### Example 3: Backfill Historical Data

1. Run `php run-backfill.php`
2. `BackfillService::backfill_agent_evaluations()`:
   - Queries all successful audits with `audit_response`
   - For each audit:
     - Checks if evaluations already exist
     - Parses `audit_response` JSON
     - Extracts `agent_evaluations`
     - Inserts into `ais_agent_evaluations`
3. Outputs statistics

---

## Security

### Authentication

1. **API Token Authentication**
   - Token stored in WordPress option: `ai_audit_secret_token`
   - Required header: `X-Audit-Token`
   - Verified by `TokenVerification` middleware

2. **WordPress Capabilities**
   - Admin functions require `manage_options` capability
   - AJAX handlers check user permissions

### Data Protection

1. **SQL Injection Prevention**
   - All queries use `$wpdb->prepare()`
   - Input sanitization via WordPress functions

2. **Input Sanitization**
   - `sanitize_text_field()` for text
   - `sanitize_email()` for emails
   - `intval()` for integers
   - `wp_unslash()` for form data

3. **Output Escaping**
   - WordPress escaping functions used in templates
   - JSON encoding for API responses

### Credentials

**Location:** `credentials.txt` (DO NOT COMMIT TO GIT)

**Contains:**
- WordPress credentials
- Database credentials
- N8N API key
- Security tokens

---

## Dependencies

### Required

1. **WordPress** (5.0+)
   - Core platform
   - REST API
   - Database abstraction

2. **FluentSupport Plugin**
   - Support ticket system
   - API: `FluentSupportApi('tickets')`
   - Tables: `fs_persons`, `fs_tickets`, etc.

3. **N8N** (External Service)
   - Workflow automation
   - AI processing
   - Webhook receiver

4. **MySQL/MariaDB**
   - Database storage
   - WordPress database

### Optional

- **Pinecone** (for documentation search)
- **AI Service** (via N8N - OpenAI, Anthropic, etc.)

---

## Constants

### Plugin Constants

```php
SUPPORT_OPS_VERSION = '10.0'
SUPPORT_OPS_PLUGIN_DIR = plugin directory path
SUPPORT_OPS_PLUGIN_URL = plugin URL
N8N_FORCE_URL = 'https://team.junior.ninja/webhook/force-audit'
```

### WordPress Options

- `ai_audit_secret_token` - API security token
- `ai_audit_system_message` - AI system message (prompt)
- `ai_audit_system_message_updated` - Last update timestamp
- `ai_audit_db_version` - Database version

---

## Credentials Reference

**File:** `credentials.txt`

### WordPress/FluentSupport
- Site URL: `https://support.junior.ninja/`
- API Username: `admin`
- Application Password: (stored in credentials.txt)

### Database
- Host: `https://support.junior.ninja/phpmyadmin/`
- Database: `db11_support`
- Username: `u11_support`
- Password: (stored in credentials.txt)
- Port: `3306`
- Prefix: `yfao_`

### N8N
- URL: `https://team.junior.ninja/`
- Email: `reachkamrul@gmail.com`
- Password: (stored in credentials.txt)
- API Key: (JWT token in credentials.txt)

### Security Token
- Token: (stored in credentials.txt and WordPress option)

---

## Troubleshooting

### Common Issues

1. **Audits stuck in "pending"**
   - Check N8N webhook is receiving requests
   - Verify N8N workflow is running
   - Check API token is correct

2. **Agent evaluations missing**
   - Run backfill: `php run-backfill.php`
   - Check `audit_response` contains `agent_evaluations`
   - Verify `EvaluationSaver` is being called

3. **Shift compliance not working**
   - Verify shifts are created in `ais_agent_shifts`
   - Check datetime format matches
   - Ensure `ShiftChecker` is called during audit

4. **API authentication failing**
   - Verify token in WordPress option matches request header
   - Check token hasn't expired
   - Regenerate token in API Config page

---

## Development Notes

### Code Style
- PSR-4 autoloading
- Namespace: `SupportOps\`
- WordPress coding standards
- OOP design patterns (Singleton, Factory, etc.)

### Database Migrations
- Auto-repair runs on `admin_init`
- Version stored in `ai_audit_db_version` option
- Migrations in `Database\Manager::auto_repair()`

### Testing
- Manual testing via admin interface
- API testing via REST endpoints
- Backfill testing via CLI script

---

## Future Enhancements

### Phase 2 Features (Noted in Code)
- Enhanced shift context
- Real-time audit processing
- Advanced analytics
- Agent comparison tools
- Automated reporting

---

**End of Documentation**

