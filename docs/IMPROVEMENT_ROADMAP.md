# Jexactyl Improvement Roadmap

A comprehensive guide for upgrading, refactoring, and improving the Jexactyl project.

---

## Table of Contents

1. [Chapter 1: Upgrading Dependencies](#chapter-1-upgrading-dependencies)
2. [Chapter 2: Refactoring Large Components](#chapter-2-refactoring-large-components)
3. [Chapter 3: Improving Type Safety](#chapter-3-improving-type-safety)
4. [Chapter 4: Security Hardening](#chapter-4-security-hardening)
5. [Chapter 5: Performance Optimization](#chapter-5-performance-optimization)
6. [Chapter 6: Testing & Quality Assurance](#chapter-6-testing--quality-assurance)
7. [Chapter 7: Documentation & Developer Experience](#chapter-7-documentation--developer-experience)
8. [Chapter 8: Code Organization & Architecture](#chapter-8-code-organization--architecture)

---

## Chapter 1: Upgrading Dependencies

### 1.1 Current State Analysis

The project has many outdated dependencies that need upgrading for security, performance, and feature improvements.

#### Frontend (npm) Dependencies Status

**Critical Security Updates Needed:**
```
axios: 0.30.0 → 1.13.2 (Known vulnerabilities)
```

**Major Version Updates:**
```
React: 18.2.0 → 19.2.3
react-dom: 18.2.0 → 19.2.3
react-router-dom: 6.8.1 → 7.11.0
@headlessui/react: 1.7.11 → 2.2.9
@heroicons/react: 1.0.6 → 2.2.0
chart.js: 3.9.1 → 4.5.1
framer-motion: 9.1.6 → 12.23.26
i18next: 22.4.10 → 25.7.3
styled-components: 5.3.6 → 6.1.19
```

**Minor/Patch Updates:**
```
@fortawesome/fontawesome-svg-core: 6.3.0 → 7.1.0
@stripe/react-stripe-js: 3.10.0 → 5.4.1
@stripe/stripe-js: 5.10.0 → 8.6.0
formik: 2.2.9 → 2.4.9
yup: 0.29.3 → 1.7.1
date-fns: 2.29.3 → 4.1.0
```

#### Backend (Composer) Dependencies

The PHP dependencies are generally up-to-date with Laravel 10. Monitor for:
- Laravel security patches
- Stripe SDK updates
- AWS SDK updates

### 1.2 Upgrade Strategy

#### Phase 1: Critical Security (Week 1)

**Priority 1 - Axios Update:**
```bash
# Install latest axios
pnpm update axios@latest

# Test all API calls
npm run test
npm run build
```

**Breaking Changes to Handle:**
- Axios 1.x uses different error response structure
- Update error handling in `resources/scripts/api/http.ts`
- Test all API interceptors

**Files to Review:**
- `resources/scripts/api/http.ts`
- `resources/scripts/api/interceptors.ts`
- All API route files in `resources/scripts/api/routes/`

#### Phase 2: React Ecosystem (Week 2-3)

**Step 1 - React 19 Migration:**

```bash
# Update React and related packages
pnpm update react@latest react-dom@latest
pnpm update @types/react@latest @types/react-dom@latest
```

**Breaking Changes:**
- React 19 removes `ReactDOM.render()` - use `createRoot()`
- PropTypes are removed - ensure TypeScript types are complete
- Some deprecated lifecycle methods removed
- New JSX transform required

**Migration Checklist:**
- [ ] Update `resources/scripts/index.tsx` to use `createRoot()`
- [ ] Remove any PropTypes usage
- [ ] Update tsconfig.json for new JSX transform
- [ ] Test all components for render issues
- [ ] Check React DevTools compatibility

**Step 2 - React Router v7:**

```bash
pnpm update react-router-dom@latest
```

**Breaking Changes:**
- New data loading API
- Layout route changes
- Navigation API updates

**Files to Update:**
- `resources/scripts/routers/*.tsx`
- All route component files

#### Phase 3: UI Libraries (Week 4)

**HeadlessUI v2:**
```bash
pnpm update @headlessui/react@latest
```

**Breaking Changes:**
- Component API changes in Dialog, Menu, Listbox
- New render prop patterns
- Improved TypeScript types

**Files to Review:**
- `resources/scripts/elements/dialog/*.tsx`
- All components using HeadlessUI

**Heroicons v2:**
```bash
pnpm update @heroicons/react@latest
```

**Breaking Changes:**
- Import paths changed from `/outline` and `/solid` to `/24/outline` and `/20/solid`
- Icon sizes standardized

**Global Find & Replace:**
```bash
# Find all icon imports
grep -r "from '@heroicons/react/outline'" resources/scripts/
grep -r "from '@heroicons/react/solid'" resources/scripts/

# Replace with v2 imports
# /outline → /24/outline
# /solid → /20/solid
```

### 1.3 Testing Strategy

**After Each Phase:**

```bash
# 1. Install dependencies
pnpm install

# 2. Type check
npm run type-check  # or tsc --noEmit

# 3. Lint
npm run lint

# 4. Build
npm run build

# 5. Test in development
npm run dev

# 6. Visual regression testing
# Test major user flows:
# - Login/Registration
# - Server creation
# - Billing checkout
# - Admin panel navigation
# - Settings pages
```

### 1.4 Rollback Plan

**Before Starting Each Phase:**

```bash
# Create backup branch
git checkout -b backup/before-phase-1
git push origin backup/before-phase-1

# Return to main branch
git checkout main
```

**If Issues Occur:**

```bash
# Revert to backup
git checkout backup/before-phase-1
git checkout -b fix/upgrade-issues

# Fix specific issues
# Then try upgrade again
```

### 1.5 Post-Upgrade Validation

**Checklist:**
- [ ] All TypeScript compiles without errors
- [ ] No console errors in browser
- [ ] All routes load correctly
- [ ] Forms submit successfully
- [ ] API calls work as expected
- [ ] Admin panel functions properly
- [ ] Billing flow completes
- [ ] Authentication works
- [ ] No visual regressions

---

## Chapter 2: Refactoring Large Components

### 2.1 Component Size Analysis

**Current Large Components:**

| Component | Lines | Complexity | Priority |
|-----------|-------|------------|----------|
| OrderContainer.tsx | 560 | High | Critical |
| ServerBillingContainer.tsx | 349 | High | High |
| Console.tsx | 271 | Medium | Medium |

### 2.2 OrderContainer.tsx Refactoring Strategy

**Current Issues:**
- 560 lines in single file
- Multiple responsibilities (data fetching, validation, payment, UI)
- Complex state management (16+ useState calls)
- Difficult to test
- Hard to maintain

**Recommended Approach:**

#### Step 1: Extract Custom Hooks

Create separate hooks for:
- `useOrderData.ts` - Data fetching logic
- `useOrderValidation.ts` - Validation logic
- `useStripePayment.ts` - Payment processing

#### Step 2: Extract Step Components

Create individual components:
- `LocationStep.tsx` - Node selection
- `ConfigurationStep.tsx` - Egg selection & variables
- `PaymentStep.tsx` - Payment details
- `ReviewStep.tsx` - Order review

#### Step 3: Benefits

- Main component reduced from 560 to ~100 lines
- Each step independently testable
- Hooks can be reused
- Easier to understand flow
- Better separation of concerns

### 2.3 Backend Controller Refactoring

**CheckoutController.php (412 lines)**

**Recommended Improvements:**

1. **Extract Request Validation**
   - Create dedicated Form Request classes
   - Move validation rules out of controller

2. **Simplify Methods**
   - Use existing service layer (already well-structured)
   - Keep controller methods focused on HTTP concerns

3. **Benefits**
   - More testable
   - Clearer separation of concerns
   - Easier to maintain

---

## Chapter 3: Improving Type Safety

### 3.1 Current Status

**Completed:**
- ✅ Replaced `any` with `unknown` in error handling
- ✅ Created type guards for API responses
- ✅ Typed API parameter functions

**Remaining:**
- ~119 `any` type usages (down from 139)
- Some implicit `any` in transformers
- Missing types for some API responses

### 3.2 Eliminate Remaining `any` Types

#### Location 1: API Transformers

**Recommended Fix:**
```typescript
// Define raw data interfaces
interface RawTaskData {
    id: number;
    sequence_id: number;
    action: string;
    payload: string;
    // ... other fields
}

// Use in transformer
static toTask = (data: RawTaskData): Models.Task => ({
    id: data.id,
    sequenceId: data.sequence_id,
    // ...
});
```

#### Location 2: Event Handlers

**Recommended Fix:**
```typescript
// Instead of (e: any)
onChange={(e: React.ChangeEvent<HTMLInputElement>) => 
    setFieldValue('name', e.target.value)
}
```

### 3.3 Add Runtime Validation

**Recommended: Use Zod for Runtime Validation**

```bash
pnpm add zod
```

**Benefits:**
- Runtime type checking
- Better error messages
- Schema documentation
- Type inference

**Example:**
```typescript
import { z } from 'zod';

const ServerSchema = z.object({
    uuid: z.string().uuid(),
    name: z.string(),
    // ... other fields
});

// Use in API calls
const server = ServerSchema.parse(data.attributes);
```

---

## Chapter 4: Security Hardening

### 4.1 Completed Security Fixes

✅ **Eliminated Raw DB Queries:**
- Created JGuardDelay model
- Replaced DB::table() with Eloquent

✅ **Improved Type Safety:**
- Type guards prevent injection in error handling

### 4.2 Input Validation Recommendations

#### Backend

**Create Form Request Classes for all endpoints:**
```php
class CreateServerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:191',
                'regex:/^[a-zA-Z0-9\s\-_]+$/',
            ],
            // ... other fields
        ];
    }
}
```

#### Frontend

**Use Yup Schemas for client-side validation:**
```typescript
const serverNameSchema = yup.object({
    name: yup
        .string()
        .required('Server name is required')
        .max(191)
        .matches(/^[a-zA-Z0-9\s\-_]+$/),
});
```

### 4.3 Additional Security Recommendations

1. **Rate Limiting**
   - Add to all sensitive endpoints
   - Stricter limits on billing/auth endpoints

2. **CSRF Protection**
   - Already enabled (Laravel default)
   - Verify in app/Http/Kernel.php

3. **XSS Prevention**
   - React escapes by default
   - Avoid `dangerouslySetInnerHTML`
   - Use DOMPurify if HTML needed

4. **Authentication & Authorization**
   - Review all policy classes
   - Ensure proper permission checks

---

## Chapter 5: Performance Optimization

### 5.1 Frontend Performance

#### Code Splitting Recommendations

**Implement Route-Based Splitting:**
```typescript
import { lazy, Suspense } from 'react';

const Overview = lazy(() => import('@admin/general/overview/OverviewContainer'));
const Servers = lazy(() => import('@admin/management/servers/ServersContainer'));

export default function AdminRouter() {
    return (
        <Suspense fallback={<Spinner />}>
            <Routes>
                <Route path="/" element={<Overview />} />
                <Route path="/servers" element={<Servers />} />
            </Routes>
        </Suspense>
    );
}
```

**Benefits:**
- Smaller initial bundle
- Faster page loads
- Better caching

#### Bundle Size Optimization

**Analyze Bundle:**
```bash
pnpm add -D vite-bundle-visualizer
npm run build
```

**Tree Shaking:**
```typescript
// BAD
import _ from 'lodash';

// GOOD
import debounce from 'lodash/debounce';
```

### 5.2 Backend Performance

#### Database Optimization

**Prevent N+1 Queries:**
```php
// BAD
$servers = Server::all();
foreach ($servers as $server) {
    echo $server->user->name;  // N+1 query
}

// GOOD
$servers = Server::with('user')->get();
```

**Add Indexes:**
```php
Schema::table('servers', function (Blueprint $table) {
    $table->index('owner_id');
    $table->index('node_id');
    $table->index(['status', 'owner_id']);
});
```

#### Caching Strategy

**Config Caching:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Data Caching:**
```php
use Illuminate\Support\Facades\Cache;

$stats = Cache::remember('dashboard.stats', 300, function () {
    return [
        'servers' => Server::count(),
        'users' => User::count(),
    ];
});
```

---

## Chapter 6: Testing & Quality Assurance

### 6.1 Current Testing Status

**Existing:**
- 75 PHP test files
- PHPUnit configured
- Some integration tests

**Missing:**
- Frontend tests
- E2E tests
- Visual regression tests

### 6.2 Testing Recommendations

#### Frontend Testing with Vitest

**Setup:**
```bash
pnpm add -D vitest @testing-library/react @testing-library/user-event
```

**Component Test Example:**
```typescript
import { render, screen } from '@testing-library/react';
import { Button } from './Button';

test('renders button with text', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
});
```

#### E2E Testing with Playwright

**Setup:**
```bash
pnpm add -D @playwright/test
npx playwright install
```

**Test Example:**
```typescript
test('user can login', async ({ page }) => {
    await page.goto('/auth/login');
    await page.fill('input[name="username"]', 'testuser');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL('/');
});
```

### 6.3 Testing Checklist

**Before Release:**
- [ ] All unit tests pass
- [ ] All feature tests pass
- [ ] Frontend component tests pass
- [ ] E2E tests pass
- [ ] Manual testing of critical paths
- [ ] Cross-browser testing
- [ ] Mobile responsive testing
- [ ] Performance testing (Lighthouse)

---

## Chapter 7: Documentation & Developer Experience

### 7.1 API Documentation

**Recommended: Use Scribe**

```bash
composer require --dev knuckleswtf/scribe
php artisan vendor:publish --tag=scribe-config
php artisan scribe:generate
```

**Document Endpoints:**
```php
/**
 * Create a new server
 * 
 * @group Server Management
 * @bodyParam name string required The server name
 * @bodyParam egg_id integer required The egg ID
 */
public function store(CreateServerRequest $request)
{
    // ...
}
```

### 7.2 Improve README.md

**Add Quick Start Section:**
- Prerequisites
- Installation steps
- Development workflow
- Testing commands
- Code quality checks

### 7.3 Create Contributing Guide

**Update CONTRIBUTING.md:**
- Code standards (PHP PSR-12, TypeScript strict)
- Testing requirements
- PR guidelines
- Commit message format

### 7.4 Architecture Decision Records (ADRs)

**Create docs/adr/ directory:**
- Document major architectural decisions
- Explain context and consequences
- Reference in future discussions

**Example ADR:**
```markdown
# ADR-001: Use Eloquent ORM Instead of Raw Queries

## Status
Accepted

## Context
Raw DB queries posed security risks

## Decision
Replace all DB::table() with Eloquent models

## Consequences
Better security, easier maintenance
```

---

## Chapter 8: Code Organization & Architecture

### 8.1 Current Architecture

```
Jexactyl/
├── app/
│   ├── Http/Controllers/    # Request handling
│   ├── Services/            # Business logic
│   ├── Models/              # Data models
│   ├── Repositories/        # Data access
│   └── Transformers/        # API responses
├── resources/scripts/
│   ├── api/                 # API client
│   ├── components/          # React components
│   └── state/               # State management
└── tests/                   # Tests
```

### 8.2 Recommended Improvements

#### Component Organization

**Improved Structure:**
```
components/
├── admin/
│   ├── management/
│   │   ├── servers/
│   │   │   ├── components/      # Reusable
│   │   │   ├── hooks/           # Custom hooks
│   │   │   └── utils/           # Utilities
│   │   ├── users/
│   │   └── nodes/
│   └── modules/
├── account/
│   ├── billing/
│   │   ├── order/
│   │   │   ├── steps/           # Step components
│   │   │   ├── hooks/           # Order hooks
│   │   │   └── OrderContainer.tsx
│   │   └── history/
│   └── settings/
└── common/                      # Shared components
    ├── forms/
    ├── tables/
    └── layouts/
```

### 8.3 Error Handling

**Global Error Boundary:**
```typescript
export class ErrorBoundary extends Component {
    state = { hasError: false };
    
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    
    render() {
        if (this.state.hasError) {
            return <ErrorFallback />;
        }
        return this.props.children;
    }
}
```

---

## Implementation Timeline

### Month 1: Foundation
- Week 1: Critical dependency updates (axios)
- Week 2: React 19 migration
- Week 3: Type safety improvements
- Week 4: Security hardening

### Month 2: Performance & Quality
- Week 1: Code splitting implementation
- Week 2: Bundle optimization
- Week 3: Backend performance
- Week 4: Testing setup

### Month 3: Refactoring
- Week 1: Large component refactoring
- Week 2: Service layer improvements
- Week 3: State management refinement
- Week 4: Documentation

### Month 4: Polish
- Week 1: Remaining dependency updates
- Week 2: E2E tests
- Week 3: Final security audit
- Week 4: Performance tuning

---

## Success Metrics

### Performance
- [ ] Initial load < 3s
- [ ] Time to Interactive < 5s
- [ ] Lighthouse score > 90

### Quality
- [ ] Test coverage > 80%
- [ ] TypeScript strict mode enabled
- [ ] Zero `any` types in new code
- [ ] All ESLint rules passing

### Security
- [ ] No high-severity vulnerabilities
- [ ] All inputs validated
- [ ] Rate limiting on endpoints
- [ ] Security headers configured

### Developer Experience
- [ ] Setup time < 15 minutes
- [ ] Build time < 30 seconds
- [ ] API documentation complete
- [ ] Contributing guide clear

---

## Conclusion

This roadmap provides a structured approach to improving Jexactyl. Each chapter can be tackled independently, allowing for incremental progress.

**Priority Order:**
1. **Security** - Always first priority
2. **Critical Bugs** - User-impacting issues
3. **Performance** - User experience
4. **Technical Debt** - Long-term maintainability
5. **Features** - New functionality

**Remember:** Incremental improvements are better than no improvements. Start small, test thoroughly, and build momentum.

---

## Quick Reference

### Commands

**Development:**
```bash
composer install
pnpm install
php artisan serve
npm run dev
```

**Testing:**
```bash
php artisan test
npm run test
npx playwright test
```

**Code Quality:**
```bash
composer cs:fix
npm run lint
npm run type-check
```

**Production:**
```bash
npm run build
php artisan config:cache
php artisan route:cache
```

### Key Files

- `docs/CODE_ANALYSIS.md` - Completed improvements analysis
- `package.json` - Frontend dependencies
- `composer.json` - Backend dependencies
- `tsconfig.json` - TypeScript configuration
- `.env.example` - Environment configuration

### Resources

- Laravel Docs: https://laravel.com/docs/10.x
- React Docs: https://react.dev
- TypeScript Handbook: https://www.typescriptlang.org/docs/
- Vite Guide: https://vitejs.dev/guide/
