# TheHUB Development Roadmap 2025
**Created:** 2025-11-14
**Budget Remaining:** $90
**Current Version:** v2.0.0
**Status:** Post-Comprehensive Audit

---

## 📊 CURRENT STATUS SUMMARY

### ✅ What's Working (Already Completed)
- ✅ Complete CRUD operations (Events, Riders, Clubs, Series, Venues, Results)
- ✅ UCI Import with encoding detection
- ✅ Import History with Rollback functionality
- ✅ Responsive UI with GravitySeries theme
- ✅ Public pages (Home, Riders, Events, Series)
- ✅ Admin authentication system
- ✅ Database schema with all required tables
- ✅ Security features (SQL injection, XSS, CSRF protection)
- ✅ License management for riders
- ✅ Category system

### ❌ Critical Issues Found
- ❌ Authentication backdoor (CRITICAL)
- ❌ Debug mode enabled in production (HIGH)
- ❌ Weak default credentials (HIGH)
- ❌ Database method bug (`getOne()` vs `getRow()`)
- ❌ Missing public clubs page
- ❌ No rate limiting on login

### ⚠️ Missing Features (User Requested)
- ⚠️ Results auto-import with event creation
- ⚠️ Permanent sidebar on desktop (claimed fixed but verify)
- ⚠️ Search/filter improvements
- ⚠️ Results display enhancements
- ⚠️ Series standings/leaderboards
- ⚠️ Event registration system

---

## 💰 BUDGET ALLOCATION ($90 Total)

### Reserve Fund: $10
Keep for unexpected issues or final polish.

### Available for Work: $80
Distributed across 3 priorities: Critical, High, Medium

---

## 🔴 PHASE 1: CRITICAL FIXES ($25 Budget)
**Timeline:** Complete within 1 day
**Goal:** Make site secure and stable

### 1.1 Remove Security Backdoor [$5]
**File:** `admin/login.php`
**Task:** Delete lines 4-10 (backdoor code)
**Priority:** CRITICAL
**Time:** 10 minutes
**Testing:** Verify backdoor URL doesn't work

```diff
- // TEMPORARY BACKDOOR
- if (isset($_GET['backdoor']) && $_GET['backdoor'] === 'dev2025') {
-     $_SESSION['admin_logged_in'] = true;
-     $_SESSION['admin_username'] = 'admin';
-     header('Location: dashboard.php');
-     exit;
- }
```

**Acceptance Criteria:**
- [ ] Code deleted
- [ ] Backdoor URL returns login page
- [ ] Normal login still works
- [ ] Committed and pushed

---

### 1.2 Disable Debug Mode [$2]
**File:** `config.php`
**Task:** Change `DEBUG` constant
**Priority:** CRITICAL
**Time:** 5 minutes

```diff
- define('DEBUG', true);
+ define('DEBUG', false);
```

Or better:
```php
define('DEBUG', env('APP_ENV') === 'development');
```

**Acceptance Criteria:**
- [ ] Debug disabled
- [ ] Error pages don't show stack traces
- [ ] Errors logged to file instead
- [ ] Committed and pushed

---

### 1.3 Secure Admin Credentials [$8]
**Files:** `config.php`, `admin/login.php`, `.env`
**Task:**
1. Create/update `.env` with strong credentials
2. Remove hint from login page
3. Force environment variables

**Priority:** CRITICAL
**Time:** 30 minutes

**Steps:**
1. Create `.env` if missing:
```env
ADMIN_USERNAME=admin_unique_name_2025
ADMIN_PASSWORD=SecureP@ssw0rd!2025_ChangeMe
```

2. Remove hint from `login.php`:
```diff
- <p class="gs-text-secondary gs-text-sm">
-     <i data-lucide="info"></i>
-     Standard login: <strong>admin / admin</strong>
- </p>
```

3. Add validation in `config.php`:
```php
$adminUser = env('ADMIN_USERNAME');
$adminPass = env('ADMIN_PASSWORD');

if (!$adminUser || !$adminPass || $adminUser === 'admin' || $adminPass === 'admin') {
    error_log('SECURITY WARNING: Change admin credentials in .env file!');
}
```

**Acceptance Criteria:**
- [ ] `.env` file has strong password
- [ ] Login hint removed
- [ ] Old credentials don't work (if changed)
- [ ] New credentials work
- [ ] Committed (but NOT .env file!)

---

### 1.4 Fix Database Method Bug [$10]
**Files:** `admin/riders.php`, `admin/events.php`, `admin/clubs.php`, `admin/series.php`, `admin/results.php`
**Task:** Replace `getOne()` with `getRow()`
**Priority:** CRITICAL
**Time:** 1 hour

**Search and Replace:**
```bash
grep -r "getOne(" admin/*.php
# Replace all with getRow()
```

**Acceptance Criteria:**
- [ ] All `getOne()` replaced with `getRow()`
- [ ] Rider editing works
- [ ] Event editing works
- [ ] All CRUD edit operations tested
- [ ] No PHP errors
- [ ] Committed and pushed

---

## 🟡 PHASE 2: HIGH PRIORITY FIXES ($30 Budget)
**Timeline:** Complete within 1 week
**Goal:** Complete missing features and improve stability

### 2.1 Create Public Clubs Page [$8]
**File:** `/clubs.php` (new)
**Task:** Create public clubs listing page
**Priority:** HIGH
**Time:** 2 hours

**Requirements:**
- Show all active clubs
- Display club info (name, city, region, website)
- Show rider count per club
- Link to filtered riders view
- Search/filter functionality
- Responsive design matching other public pages

**Template:**
```php
<?php
require_once __DIR__ . '/config.php';
$db = getDB();

$clubs = $db->getAll("
    SELECT c.id, c.name, c.short_name, c.city, c.region, c.website,
           COUNT(r.id) as rider_count
    FROM clubs c
    LEFT JOIN riders r ON c.club_id = r.id AND r.active = 1
    WHERE c.active = 1
    GROUP BY c.id
    ORDER BY c.name
");

$pageTitle = 'Klubbar';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>
<!-- Grid of club cards -->
```

**Acceptance Criteria:**
- [ ] Page created and loads
- [ ] Shows all clubs with rider counts
- [ ] Links to filtered rider view
- [ ] Mobile responsive
- [ ] Committed and pushed

---

### 2.2 Verify and Fix Import History [$5]
**File:** `admin/import-history.php`
**Task:** Test import history page and rollback
**Priority:** HIGH
**Time:** 1 hour

**Test Cases:**
1. Import UCI CSV
2. View import in history
3. Rollback import
4. Verify data deleted
5. Re-import and check

**Acceptance Criteria:**
- [ ] Import history page loads
- [ ] Shows all imports
- [ ] Rollback button works
- [ ] Data actually gets deleted
- [ ] Old values restored (for updates)
- [ ] Status changes to "rolled_back"

---

### 2.3 Implement Login Rate Limiting [$12]
**File:** `admin/login.php`, new `includes/rate-limit.php`
**Task:** Prevent brute-force attacks
**Priority:** HIGH
**Time:** 3 hours

**Implementation:**
```php
// includes/rate-limit.php
function checkRateLimit($identifier, $maxAttempts = 5, $windowSeconds = 900) {
    $key = 'rate_limit_' . md5($identifier);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_attempt' => time()];

    // Reset if window expired
    if (time() - $attempts['first_attempt'] > $windowSeconds) {
        $attempts = ['count' => 0, 'first_attempt' => time()];
    }

    // Check if blocked
    if ($attempts['count'] >= $maxAttempts) {
        $timeRemaining = $windowSeconds - (time() - $attempts['first_attempt']);
        return ['blocked' => true, 'seconds' => $timeRemaining];
    }

    // Increment counter
    $attempts['count']++;
    $_SESSION[$key] = $attempts;

    return ['blocked' => false];
}
```

**Usage in login.php:**
```php
$rateLimit = checkRateLimit($_SERVER['REMOTE_ADDR']);
if ($rateLimit['blocked']) {
    $minutes = ceil($rateLimit['seconds'] / 60);
    $error = "För många inloggningsförsök. Försök igen om $minutes minuter.";
    // ... display error
}
```

**Acceptance Criteria:**
- [ ] Max 5 attempts per IP
- [ ] 15-minute lockout
- [ ] Clear error message
- [ ] Rate limit resets after window
- [ ] Tested with multiple failed attempts
- [ ] Committed and pushed

---

### 2.4 Add Security Headers [$5]
**File:** `includes/layout-header.php`
**Task:** Add HTTP security headers
**Priority:** MEDIUM (but included in HIGH)
**Time:** 30 minutes

```php
// Add at top of layout-header.php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
```

**Acceptance Criteria:**
- [ ] Headers present in all responses
- [ ] Verified with browser DevTools
- [ ] No functionality broken
- [ ] Committed and pushed

---

## 🟢 PHASE 3: MEDIUM PRIORITY FEATURES ($20 Budget)
**Timeline:** Complete within 2 weeks
**Goal:** Enhance user experience and complete requested features

### 3.1 Results Import with Auto-Create Events [$15]
**File:** `admin/import-results.php` (enhance existing)
**Task:** Smart event detection and creation during results import
**Priority:** MEDIUM
**Time:** 4 hours

**Current Status:** File exists but needs enhancement

**Requirements:**
1. Parse event name/date from results CSV
2. Search for matching event in database
3. If not found, show confirmation dialog:
   - "Event 'XYZ' on 2025-05-15 not found. Create it?"
   - Pre-fill event details from CSV
   - User confirms or edits
4. Create event if confirmed
5. Import results linked to event
6. Track in import history

**CSV Format Expected:**
```csv
Event Name,Date,Rider Name,Position,Time,Category
"Gravity Enduro #1",2025-05-15,"John Doe",1,01:23:45,"Men Elite"
```

**Acceptance Criteria:**
- [ ] CSV parsing works
- [ ] Event detection logic
- [ ] Confirmation dialog shows
- [ ] Event auto-created
- [ ] Results linked correctly
- [ ] Import history tracked
- [ ] Rollback works
- [ ] Committed and pushed

---

### 3.2 Verify Sidebar Behavior on Desktop [$2]
**Files:** `assets/gravityseries-theme.css`, all pages
**Task:** Test and fix sidebar visibility
**Priority:** MEDIUM
**Time:** 30 minutes

**User Complaint:** "Sidebar not permanent on desktop even though I fixed it"

**Test Cases:**
1. Load page on desktop (>1024px width)
2. Sidebar should be visible by default
3. Should NOT hide unless mobile
4. Check all admin pages
5. Check CSS media queries

**Potential Issues:**
- CSS not loaded
- Media query wrong
- JavaScript hiding it
- Cache not cleared

**Fix if needed:**
```css
/* In gravityseries-theme.css */
@media (min-width: 1024px) {
    .gs-sidebar {
        display: block !important;
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
    }

    .gs-hamburger {
        display: none !important;
    }
}
```

**Acceptance Criteria:**
- [ ] Desktop sidebar always visible
- [ ] Mobile hamburger menu works
- [ ] Tested on multiple browsers
- [ ] Cache-busting applied if needed
- [ ] Committed and pushed

---

### 3.3 Series Standings/Leaderboards [$3]
**File:** `/series.php`, `/series-standings.php` (new)
**Task:** Show series leaderboards with points
**Priority:** MEDIUM
**Time:** 1 hour

**Requirements:**
1. Click on series shows standings
2. Calculate total points per rider
3. Sort by points descending
4. Show wins, podiums, total races
5. Filter by category

**Database Query:**
```sql
SELECT
    r.id, r.firstname, r.lastname,
    SUM(res.points) as total_points,
    COUNT(res.id) as total_races,
    COUNT(CASE WHEN res.position = 1 THEN 1 END) as wins,
    COUNT(CASE WHEN res.position <= 3 THEN 1 END) as podiums
FROM riders r
JOIN results res ON r.id = res.cyclist_id
JOIN events e ON res.event_id = e.id
WHERE e.series_id = ?
GROUP BY r.id
ORDER BY total_points DESC, wins DESC
```

**Acceptance Criteria:**
- [ ] Standings page created
- [ ] Points calculated correctly
- [ ] Sorted by points
- [ ] Category filter works
- [ ] Responsive design
- [ ] Committed and pushed

---

## 🔵 PHASE 4: POLISH & ENHANCEMENTS ($5 Budget + $10 Reserve)
**Timeline:** Future / As needed
**Goal:** Final polish and nice-to-have features

### 4.1 Search/Filter Improvements [$3]
**Files:** Various public pages
**Task:** Enhanced search on public pages
**Priority:** LOW
**Time:** 1 hour

**Enhancements:**
- Debounced search (wait 300ms before searching)
- Search result count
- Clear search button
- Remember search in URL params
- Highlight search terms

---

### 4.2 Export Functionality [$5]
**File:** New `includes/export.php`
**Task:** Export data to CSV/Excel
**Priority:** LOW
**Time:** 2 hours

**Features:**
- Export riders list to CSV
- Export event results to CSV
- Export series standings to PDF
- Download button on list pages

---

### 4.3 Email Notifications [$7]
**File:** New `includes/email.php`
**Task:** Send emails for imports
**Priority:** LOW
**Time:** 3 hours

**Features:**
- Email admin after successful import
- Email on import errors
- Summary of what was imported
- Requires mail server setup

---

## 📋 TESTING CHECKLIST

After each phase, run these tests:

### Security Tests
- [ ] No backdoor access
- [ ] Debug mode off
- [ ] Strong credentials required
- [ ] Rate limiting works
- [ ] CSRF tokens present
- [ ] XSS attempts blocked
- [ ] SQL injection attempts blocked

### Functionality Tests
- [ ] Login/logout works
- [ ] All CRUD operations work
- [ ] Import works
- [ ] Import history works
- [ ] Rollback works
- [ ] Public pages load
- [ ] Search works
- [ ] Filters work

### UI/UX Tests
- [ ] Mobile responsive
- [ ] Sidebar correct on desktop
- [ ] Hamburger works on mobile
- [ ] Forms validate
- [ ] Error messages clear
- [ ] Success messages show
- [ ] Loading states present

---

## 🚀 DEPLOYMENT CHECKLIST

Before deploying fixes:

### Pre-Deployment
- [ ] All changes committed
- [ ] Tests passed locally
- [ ] Database backups created
- [ ] `.env` file configured
- [ ] Debug mode disabled
- [ ] Error logging enabled

### Deployment Steps
1. [ ] Upload files via FTP/deploy script
2. [ ] Run database migrations if any
3. [ ] Clear PHP opcode cache
4. [ ] Clear browser cache
5. [ ] Test critical functions
6. [ ] Monitor error logs

### Post-Deployment
- [ ] Verify login works
- [ ] Test CRUD operations
- [ ] Check import functionality
- [ ] Verify public pages load
- [ ] Monitor for 24 hours

---

## 📊 PRIORITY MATRIX

| Task | Priority | Budget | Time | Impact | Effort |
|------|----------|--------|------|--------|--------|
| Remove backdoor | CRITICAL | $5 | 10m | HIGH | LOW |
| Disable debug | CRITICAL | $2 | 5m | HIGH | LOW |
| Secure credentials | CRITICAL | $8 | 30m | HIGH | LOW |
| Fix DB methods | CRITICAL | $10 | 1h | HIGH | MED |
| Clubs page | HIGH | $8 | 2h | MED | MED |
| Import history | HIGH | $5 | 1h | MED | LOW |
| Rate limiting | HIGH | $12 | 3h | HIGH | HIGH |
| Security headers | HIGH | $5 | 30m | MED | LOW |
| Results import | MEDIUM | $15 | 4h | HIGH | HIGH |
| Sidebar fix | MEDIUM | $2 | 30m | LOW | LOW |
| Leaderboards | MEDIUM | $3 | 1h | MED | LOW |
| Search enhance | LOW | $3 | 1h | LOW | LOW |
| Export | LOW | $5 | 2h | LOW | MED |
| Email | LOW | $7 | 3h | LOW | HIGH |

---

## 💡 RECOMMENDATIONS

### Do First (Essential)
1. Remove backdoor ← CRITICAL
2. Disable debug ← CRITICAL
3. Secure credentials ← CRITICAL
4. Fix database methods ← Breaks editing

### Do Next (Important)
5. Rate limiting ← Security
6. Clubs page ← Completeness
7. Security headers ← Best practice

### Do Later (Nice-to-have)
8. Results import enhancement ← UX improvement
9. Leaderboards ← Feature completion
10. Search improvements ← Polish

---

## 🎯 SUCCESS METRICS

### Phase 1 Success (Critical Fixes)
- ✅ No security vulnerabilities in audit
- ✅ All CRUD operations work
- ✅ Strong authentication required
- ✅ No debug info leaked

### Phase 2 Success (High Priority)
- ✅ Rate limiting prevents brute force
- ✅ All public pages functional
- ✅ Import history complete
- ✅ Security headers present

### Phase 3 Success (Medium Priority)
- ✅ Results import works end-to-end
- ✅ Sidebar behaves correctly
- ✅ Leaderboards display

### Overall Success
- ✅ Site secure and stable
- ✅ All user-requested features complete
- ✅ Professional production quality
- ✅ Under $90 budget
- ✅ Ready for public launch

---

## 📞 NEXT STEPS

1. **Review this roadmap** with stakeholders
2. **Prioritize** based on business needs
3. **Start Phase 1** immediately (critical security fixes)
4. **Test thoroughly** after each phase
5. **Deploy incrementally** rather than big bang
6. **Monitor** production after deployment
7. **Iterate** based on user feedback

---

**Roadmap Version:** 1.0
**Last Updated:** 2025-11-14
**Budget Allocation:** $80 (+ $10 reserve)
**Estimated Timeline:** 2-4 weeks for all phases

For bug details, see `BUG-REPORT.md`
For security details, see `SECURITY-AUDIT.md`

---

*This roadmap is a living document. Adjust priorities based on business needs and user feedback.*
