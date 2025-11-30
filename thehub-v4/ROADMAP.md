# TheHUB V4 - Development Roadmap

**Last Updated:** 2024-11-30  
**Launch Target:** January 17-19, 2025  
**Days Remaining:** ~45 days

---

## 📊 Current Status

### ✅ COMPLETED
- [x] Phase 1: V3 Design Migration (Design, Navigation, Basic Layout)
- [x] Phase 2: Polish & Icons (Lucide SVG icons, Typography)
- [x] Phase 2.5: GravitySeries Branding (Colors defined, awaiting fonts)
- [x] Phase 3: Data Integration Started (Dashboard, Calendar, Results views)
- [x] Phase 4: Interactive Features (Database search, Ranking view, Filters)

### 🚧 IN PROGRESS
- [ ] Backend API Completion (riders, events, results endpoints)
- [ ] Serie System Implementation (Critical - PRIO 1)
- [ ] Font Integration from gravityseries.se/branding/

### ⏳ PENDING
- [ ] Phase 5: Profiles & Deep Links
- [ ] CSV Import Tools
- [ ] Data Migration (V2 → V4)
- [ ] Testing & Polish
- [ ] Production Deployment

---

## 🎯 IMMEDIATE PRIORITIES (Week 1)

### Priority 1: Serie System ⭐ CRITICAL
**Status:** Not Started  
**Estimated Time:** 1 hour with Claude Code  
**Blockers:** None

**Requirements:**
- Create/verify `series` table in database
- Populate with 6 known series:
  1. Capital Gravity Series (6 events, count 4 best)
  2. Götaland Gravity Series (5 events)
  3. GravitySeries Downhill (3 events)
  4. GravitySeries Total (19 events - aggregates ALL)
  5. Jämtland GravitySeries (5 events, count 5 best)
  6. SweCup Enduro 2025 (6 events, count 5 best)
- Create API endpoints:
  - `/api/series.php` - List all series
  - `/api/series-detail.php` - Single series with events
  - `/api/series-standings.php` - Calculate standings with "best N" logic
- Frontend views:
  - Series overview page
  - Series detail page with standings tables
- URL routing for `/series` and `/series/:id`

**Success Criteria:**
- [ ] Series overview shows 6 series cards
- [ ] Click series → Opens detail page with events
- [ ] Standings calculate correctly (e.g., Capital counts 4 best results)
- [ ] Search & filter by category works
- [ ] Matches V3 design exactly

**Reference:** `/mnt/user-data/outputs/SERIES-SYSTEM-IMPLEMENTATION.md`

---

### Priority 2: Font Integration from GravitySeries Branding
**Status:** Not Started  
**Estimated Time:** 30 minutes  
**Blockers:** Need to inspect https://gravityseries.se/branding/

**Requirements:**
- Inspect https://gravityseries.se/branding/ for available fonts
- Download or link to font files
- Update `tokens.css` with correct font-family
- Test font loading across all views
- Fallback to system-ui if fonts fail

**Files to Update:**
- `/thehub-v4/assets/css/tokens.css` - Update --font-family
- `/thehub-v4/index.php` - Add font preload/link in <head>

**Success Criteria:**
- [ ] Correct GravitySeries font loads on all pages
- [ ] Falls back gracefully if font unavailable
- [ ] No FOUT (Flash of Unstyled Text)

---

### Priority 3: Backend API Completion
**Status:** In Progress  
**Estimated Time:** 1 hour with Claude Code  
**Blockers:** None

**Missing Endpoints:**
- [ ] `/api/rider.php?id=X` - Single rider with stats
- [ ] `/api/event.php?id=X` - Single event with details
- [ ] `/api/club.php?id=X` - Single club with members
- [ ] `/api/results.php` - Fix to include rider/club names (no more "Okänd")

**Success Criteria:**
- [ ] All endpoints return valid JSON
- [ ] Results show rider names, not "Okänd"
- [ ] Event details show participant counts
- [ ] Rider profiles load with stats

**Reference:** `/mnt/user-data/outputs/CLAUDE-CODE-BACKEND-FIX.md`

---

## 📅 WEEK-BY-WEEK PLAN

### Week 1 (Nov 30 - Dec 6): Foundation
**Goal:** Backend solid + Serie system working

- [x] Define series structure
- [ ] Implement backend API (all endpoints)
- [ ] Implement serie system (overview + detail)
- [ ] Font integration
- [ ] Test with real data samples

**Deliverable:** Working series pages, all APIs functional

---

### Week 2 (Dec 7 - Dec 13): Complete Features
**Goal:** V4 feature-complete

- [ ] Phase 5: Rider Profiles (stats, results, ranking)
- [ ] Phase 5: Club Profiles (members, results)
- [ ] Phase 5: Event Details (results by category)
- [ ] URL routing & deep links
- [ ] Admin integration (links, shared auth)

**Deliverable:** All V4 features implemented

---

### Week 3 (Dec 14 - Dec 20): Import Tools
**Goal:** Data migration ready

- [ ] CSV import tool (riders, events, results)
- [ ] Duplicate detection
- [ ] Data validation
- [ ] Dry-run mode
- [ ] Bulk operations (update, delete, recalculate)

**Deliverable:** Import tools tested with sample data

---

### Week 4 (Dec 21 - Dec 27): Data Migration
**Goal:** All data in V4

- [ ] Export V2 data as CSV
- [ ] Import existing data (3,754 riders, 26 events)
- [ ] Import new riders (~1,500 new)
- [ ] Import new events (~50 new)
- [ ] Import all results
- [ ] Verify data integrity
- [ ] Recalculate all rankings

**Deliverable:** V4 database complete (~5,000 riders, 70+ events)

---

### Week 5 (Dec 28 - Jan 3): Testing & Polish
**Goal:** Production-ready

- [ ] Internal testing (all features)
- [ ] Beta testing (10-20 users)
- [ ] Bug fixes
- [ ] Performance optimization
- [ ] Mobile testing
- [ ] Cross-browser testing

**Deliverable:** Zero critical bugs

---

### Week 6 (Jan 4 - Jan 10): Pre-Launch
**Goal:** Deployment ready

- [ ] Final data verification
- [ ] Backup V2 completely
- [ ] Set up monitoring & logging
- [ ] SSL/CDN configuration
- [ ] Final performance tests
- [ ] Soft launch (limited users)

**Deliverable:** V4 live but not announced

---

### Week 7 (Jan 11 - Jan 17): Launch Week
**Goal:** Public launch

- [ ] Monitor soft launch
- [ ] Fix any critical issues
- [ ] Full launch (Jan 17-19)
- [ ] Social media announcement
- [ ] Email to clubs/riders
- [ ] Celebrate! 🎉

**Deliverable:** V4 LIVE!

---

## 🔧 Technical Debt & Future Enhancements

### Post-Launch (Week 8+)
- [ ] Analytics & Insights (charts, trends)
- [ ] Mobile PWA (offline support, add to homescreen)
- [ ] Push notifications
- [ ] Advanced admin tools
- [ ] PDF export for results
- [ ] Photo galleries per event
- [ ] Social sharing features

---

## 📝 Data Requirements

### Current Database
- Riders: ~3,754
- Clubs: ~289
- Events: ~26
- Results: ~1,772

### To Import Before Launch
- New Riders: +1,500
- New Events: +40-50
- New Results: ~1,000+

### Total Target (Launch)
- Riders: ~5,000+
- Clubs: ~300+
- Events: ~70+
- Results: ~3,000+

---

## 🎨 Design Tokens

### Colors (from GravitySeries)
```css
--gs-secondary: #323539;
--gs-enduro-yellow: #FFE009;
--gs-blue: #004a98;
--gs-ges-orange: #EF761F;
--gs-ggs-green: #8A9A5B;
--gs-gss-purple: #6B4C9A;
```

### Fonts (from gravityseries.se/branding/)
**Status:** To be determined
- [ ] Inspect branding page
- [ ] Download/link fonts
- [ ] Update tokens.css

---

## 🚨 Critical Path Items

These MUST be done before launch:

1. ✅ Serie system working (for standings)
2. ⏳ Backend API complete (for data display)
3. ⏳ CSV import tools (for data migration)
4. ⏳ Data migration complete (all riders/events)
5. ⏳ Rider profiles working (user engagement)
6. ⏳ Mobile responsive (70% users on mobile)
7. ⏳ Performance optimized (<2s page loads)

---

## 📊 Success Metrics

### Technical
- Page load time: <2 seconds
- Error rate: <0.1%
- Mobile responsive: 100%
- Cross-browser compatible: Chrome, Safari, Firefox, Edge

### User
- User engagement: Average time on site >3 minutes
- Search usage: >50% users search for themselves
- Profile views: >80% riders view their profile
- Return visits: >60% within 7 days

---

## 🔄 How to Use This Roadmap

### For Developers (Human & Claude)
1. **Check** this file before starting any work
2. **Update** status after completing tasks
3. **Add** new tasks as they arise
4. **Review** weekly progress

### For Claude Code
1. **Read** this file at start of each session
2. **Reference** current priorities
3. **Update** completed items
4. **Flag** blockers or issues

### Status Indicators
- ✅ COMPLETED - Done, tested, working
- 🚧 IN PROGRESS - Currently being worked on
- ⏳ PENDING - Waiting to start
- 🚨 BLOCKED - Has blockers, needs attention
- ⚠️ AT RISK - May miss deadline

---

## 📞 Quick Reference

### Key Files
- `/thehub-v4/index.php` - Main frontend
- `/thehub-v4/assets/js/app.js` - Frontend logic
- `/thehub-v4/assets/css/main.css` - Style imports
- `/thehub-v4/backend/public/api/` - API endpoints
- `/thehub-v4/backend/config/database.php` - DB config

### Key Documentation
- `/mnt/user-data/outputs/SERIES-SYSTEM-IMPLEMENTATION.md` - Serie system spec
- `/mnt/user-data/outputs/CLAUDE-CODE-BACKEND-FIX.md` - Backend API spec
- `/mnt/user-data/outputs/V4-LAUNCH-ROADMAP-5-WEEKS.md` - Original 5-week plan
- `/mnt/user-data/outputs/PHASE-4-PROMPT.md` - Phase 4 implementation
- `/mnt/user-data/outputs/PHASE-5-PROMPT.md` - Phase 5 implementation

### Database
- Host: localhost
- Database: u994733455_thehub
- Tables: riders, events, results, clubs, series, ranking_points

---

**Last Modified:** 2024-11-30  
**Next Review:** 2024-12-01  
**Owner:** JALLE + Claude + Claude Code
