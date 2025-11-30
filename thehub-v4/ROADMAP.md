# TheHUB V4 - Development Roadmap

**Last Modified:** 2024-11-30
**Current Version:** v57
**Branch:** claude/migrate-thehub-v4-01GJu9BkvaCkJPCGGPYtwhWG

---

## Completed Phases

### Phase 4: Interactive Features ✅
- [x] Database integration
- [x] Ranking system
- [x] Filters implementation
- **Commit:** d6644e3

### Phase 5: Profile Pages & Routing ✅
- [x] Rider profile pages
- [x] Event profile pages
- [x] Club profile pages
- [x] URL routing with query parameters
- [x] Deep linking support
- **Commit:** e85423c

### Phase 6: Backend API Completion ✅
- [x] rider.php - Full rider profile with stats
- [x] event.php - Event details with participants
- [x] events.php - Event listing with filters
- [x] results.php - Results with rider/club JOINs
- [x] riders.php - Rider listing with points
- [x] ranking.php - Ranking with CORS
- [x] stats.php - Dashboard statistics
- [x] clubs.php - Club listing (NEW)
- [x] club.php - Club profile with members (NEW)
- **Commit:** 80cf478

### Phase 7: Serie System Implementation ✅
- [x] series.php API - List all series with metadata
- [x] series-detail.php API - Single series with events
- [x] series-standings.php API - Standings with "best N" logic
- [x] Frontend series overview page
- [x] Frontend series detail page
- [x] Category filtering in standings
- [x] URL routing for series pages
- **Commit:** 6f8fc05

### Phase 8: CSS Layout Fix ✅
- [x] Critical CSS reset (box-sizing, overflow)
- [x] Horizontal scroll prevention
- [x] Viewport meta tag update
- [x] Layout locked like V3
- **Commit:** 56be118

---

## Current Priorities

### Priority 1: Serie System ✅ COMPLETED
**Status:** Fully implemented and deployed
**Files:**
- /backend/public/api/series.php
- /backend/public/api/series-detail.php
- /backend/public/api/series-standings.php
- /assets/js/app.js (loadSeries, loadSeriesDetail, etc.)

### Priority 2: Font Integration ✅ REVIEWED
**Status:** NO CUSTOM FONTS NEEDED
**Findings:**
- [x] Checked gravityseries.se/branding - 403 Forbidden
- [x] Searched entire codebase for font files - none found
- [x] V3 uses system fonts: `system-ui,-apple-system,'Segoe UI',Roboto,sans-serif`
- [x] No @font-face rules in V3 or GravitySeries theme
- **Decision:** Keep system fonts (better performance, no loading delay)
- **Future:** If custom fonts become available, add to /assets/fonts/ and update tokens.css

### Priority 3: Backend API Completion ✅ COMPLETED
**Status:** All endpoints created and tested
**Files Created:** 12 API endpoints in /backend/public/api/

---

## Next Phase Tasks

### Priority 4: Performance & Polish
- [ ] Add loading skeletons
- [ ] Implement image lazy loading
- [ ] Add error boundaries
- [ ] Cache API responses (localStorage)
- [ ] Add pull-to-refresh on mobile

### Priority 5: Admin Panel Enhancements
- [ ] Event management CRUD
- [ ] Results import tool
- [ ] Series management
- [ ] User management

### Priority 6: PWA Features
- [ ] Service worker
- [ ] Offline support
- [ ] Install prompt
- [ ] Push notifications

---

## API Endpoints Reference

| Endpoint | Method | Parameters | Description |
|----------|--------|------------|-------------|
| /api/riders.php | GET | limit, offset | List riders |
| /api/rider.php | GET | id | Single rider profile |
| /api/events.php | GET | series, discipline | List events |
| /api/event.php | GET | id | Single event |
| /api/results.php | GET | event_id, rider_id | Results |
| /api/ranking.php | GET | discipline | Rankings |
| /api/clubs.php | GET | - | List clubs |
| /api/club.php | GET | id | Single club |
| /api/stats.php | GET | - | Dashboard stats |
| /api/series.php | GET | - | List all series |
| /api/series-detail.php | GET | slug | Single series |
| /api/series-standings.php | GET | slug, category | Series standings |

---

## Git Commits Log

```
56be118 Fix horizontal scroll/bounce issue - lock layout
6f8fc05 Phase 7: Implement complete Serie system
80cf478 Phase 6: Fix and complete backend API endpoints
e85423c Phase 5: Add profile pages, URL routing & deep linking
d6644e3 Phase 4: Add interactive features - Database, Ranking & Filters
```

---

## Notes

- Database: MySQL with PDO connections
- Series identified by events.type field (no separate series table needed)
- "Best N results" logic implemented in series-standings.php
- V4 layout now matches V3 horizontal lock behavior
- API file permissions fixed: chmod 644 on all .php files (2024-11-30)
