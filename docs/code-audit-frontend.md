# Code Audit — Frontend Patterns

> Comprehensive audit of React component patterns, hooks, state management, and UI duplication across the frontend codebase.

---

## FE-1: Status Color/Badge Logic Duplicated in 8+ Files

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `resources/scripts/components/account/billing/orders/OrdersContainer.tsx` (line ~74)
  - `resources/scripts/components/admin/modules/billing/orders/OrdersTable.tsx` (line ~70)
  - `resources/scripts/components/account/donations/DonationHistoryContainer.tsx` (line ~24)
  - `resources/scripts/components/admin/modules/billing/donations/DonationsTable.tsx` (line ~39)
  - `resources/scripts/components/server/console/Console.tsx` (line ~96)
  - `resources/scripts/components/server/console/ServerConsoleContainer.tsx` (line ~26)
  - `resources/scripts/components/account/tickets/TicketContainer.tsx` (line ~16)
  - `resources/scripts/components/account/tickets/view/ViewTicketContainer.tsx` (line ~14)
- **Problem:** Identical switch statements mapping status strings to colors/classes:
  ```typescript
  case 'processed': return 'success';     // or 'bg-green-500/5 hover:bg-green-500/10'
  case 'failed': return 'danger';         // or 'bg-red-500/5 hover:bg-red-500/10'
  case 'pending': return 'warn';          // or 'bg-yellow-500/5 hover:bg-yellow-500/10'
  ```
  Row coloring logic also duplicated between `OrdersContainer` and `OrdersTable`.
- **Why it matters:** Changing a status color requires editing 8+ files. High risk of inconsistency.
- **Recommended refactor:** Create `resources/scripts/lib/statusColors.ts`:
  ```typescript
  export const getStatusColor = (status: string): string => { ... }
  export const getStatusRowClass = (status: string): string => { ... }
  ```
- **Suggested abstraction:** `lib/statusColors.ts`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## FE-2: Form Error Handling Pattern Repeated 60+ Times

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:** 60+ Formik-based form components across:
  - `resources/scripts/components/account/forms/` (12+ files)
  - `resources/scripts/components/admin/modules/billing/**/` (40+ files)
  - `resources/scripts/components/admin/modules/auth/` (8+ files)
  - Plus many more
- **Problem:** Identical `.catch()` / `clearFlashes` / `setSubmitting(false)` pattern:
  ```typescript
  .catch(error => {
      console.error(error);
      clearAndAddHttpError({ key: 'xxx', error });
  })
  .finally(() => setSubmitting(false));
  ```
  Found identically in `CreateApiKeyForm.tsx` (lines ~39-44), `CouponForm.tsx` (lines ~182-193), `UpdatePasswordForm.tsx` (lines ~46-54), `NewTicketForm.tsx` (lines ~37-46), and 55+ more.
- **Why it matters:** Any improvement to error handling (e.g., adding toast notifications, retry logic) requires updating 60+ files.
- **Recommended refactor:** Create `useFormSubmit` hook:
  ```typescript
  const { submit, isSubmitting } = useFormSubmit({
      key: 'formKey',
      onSubmit: async (values) => { ... },
  });
  ```
- **Suggested abstraction:** `resources/scripts/hooks/useFormSubmit.ts`
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## FE-3: Modal State Management — 3 Different Approaches in 32 Components

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:** 32 modal/dialog components across the codebase including:
  - `AccountAlertHistoryModal`, `ModpackInstallModal`, `ServerGroupDialog`
  - `SetupTOTPDialog`, `SearchModal`, `FileNameModal`, `ChmodFileModal`
  - `EditServerDialog`, `ReinstallServerDialog`, `EditSubuserModal`
  - `TaskDetailsModal`, `EmailLogDetailModal`, and 20+ more
- **Problem:** Three inconsistent patterns:
  1. **Boolean state:** `const [open, setOpen] = useState<boolean>(false)`
  2. **Enum state:** `export interface VisibleDialog { open: 'index' | 'modify' | 'delete' | 'add' | 'none' }`
  3. **Tab state:** `const [activeTab, setActiveTab] = useState<TabType>('overview')`
- **Why it matters:** Inconsistent developer experience. New developers must learn 3 patterns.
- **Recommended refactor:** Create `useModalState` hook:
  ```typescript
  const { isOpen, open, close, toggle } = useModalState();
  ```
  For multi-step modals, use `useMultiStepModal` hook.
- **Suggested abstraction:** `resources/scripts/hooks/useModalState.ts`
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## FE-4: Hardcoded Status Values — 50+ Locations, No Constants

- **Severity:** Medium
- **Category:** Consistency / Maintainability
- **Affected files:** 50+ component files throughout the frontend
- **Problem:** Status values are hardcoded string literals everywhere:
  ```typescript
  'processed', 'failed', 'pending', 'expired'  // in 20+ order-related files
  'completed', 'pending'                        // in donation files
  'stripe', 'mollie', 'paypal'                 // processor names
  ```
  No enum, no constants, no single source of truth. Changing a single status value requires updates in 5-8 files.
- **Why it matters:** Easy to introduce typos. Refactoring status names is risky and tedious.
- **Recommended refactor:** Create `resources/scripts/constants/statuses.ts`:
  ```typescript
  export const OrderStatus = { PROCESSED: 'processed', FAILED: 'failed', PENDING: 'pending' } as const;
  export const PaymentProcessor = { STRIPE: 'stripe', MOLLIE: 'mollie', PAYPAL: 'paypal' } as const;
  ```
- **Suggested abstraction:** `resources/scripts/constants/statuses.ts`
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## FE-5: Only 1 Custom Hook in Entire Codebase

- **Severity:** Medium
- **Category:** Architecture
- **Affected files:** `resources/scripts/hooks/useEmailVerification.ts` (only custom hook)
- **Problem:** The entire frontend has only 1 custom hook despite 396+ components and many repeated patterns (form submission, modal state, table filtering, debounced search, pagination, API queries). All repeated logic is inline in components.
- **Why it matters:** Massive missed opportunity for code reuse and consistency.
- **Recommended refactor:** Create hooks for the most common patterns:
  - `useFormSubmit` — standardize form submission (60+ forms)
  - `useModalState` — standardize modal open/close (32 modals)
  - `useTableFilters` — standardize filter/sort/pagination (20+ tables)
  - `useDebouncedSearch` — standardize search debounce (15+ search inputs)
  - `useStatusColor` — standardize status-to-color mapping (8+ components)
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## FE-6: Table Loading State — 3 Different Approaches

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:** 50+ table/list components
- **Problem:** Three approaches to loading states:
  1. Boolean flag: `const [loading, setLoading] = useState(true)` with manual toggle
  2. React-Query pattern: `const { data, isLoading }` with automatic state
  3. Inline conditional: `{data ? <Table /> : <Spinner />}` without formal state
- **Why it matters:** Inconsistent user experience — some tables show spinners, others show skeletons, others show nothing during loading.
- **Recommended refactor:** Standardize on one approach (preferably React-Query pattern with consistent loading/empty/error states).
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## FE-7: `console.error` Used for Logging — 147 Instances

- **Severity:** Low
- **Category:** Consistency
- **Affected files:** 147 instances across the codebase
- **Problem:** `console.error()` used extensively for error logging instead of a centralized error reporting mechanism.
- **Why it matters:** No error aggregation, monitoring, or structured logging in production.
- **Recommended refactor:** Create error reporting utility. For now, at minimum, wrap in a function that can be enhanced later:
  ```typescript
  export const reportError = (error: unknown, context?: string) => {
      console.error(context, error);
      // Future: send to error tracking service
  };
  ```
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## FE-8: Permission String Parsing Duplicated

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:**
  - Permission-related components (PermissionRow, EditSubuserModal, and 3+ more)
- **Problem:** Permission string parsing (splitting on `.`, checking prefixes) is repeated inline in multiple components.
- **Why it matters:** Permission format changes require updates in multiple places.
- **Recommended refactor:** Create `resources/scripts/lib/permissions.ts` utility.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## FE-9: Repeated Delete Confirmation Dialogs

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:** Multiple admin and server management components
- **Problem:** Delete confirmation dialogs with identical structure (title, warning message, confirm/cancel buttons) are implemented inline in many components instead of using a shared component.
- **Why it matters:** Visual inconsistency and repetitive code.
- **Recommended refactor:** Create reusable `ConfirmDeleteDialog` component:
  ```tsx
  <ConfirmDeleteDialog
      title="Delete Server"
      message="This action cannot be undone."
      onConfirm={handleDelete}
      isOpen={showDelete}
      onClose={() => setShowDelete(false)}
  />
  ```
- **Suggested abstraction:** `resources/scripts/components/elements/ConfirmDeleteDialog.tsx`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## FE-10: React-Query Invalidation Inconsistency

- **Severity:** Low
- **Category:** Consistency
- **Affected files:** Various components using React-Query
- **Problem:** Query invalidation after mutations is handled inconsistently:
  - Some components invalidate specific query keys
  - Some invalidate entire categories
  - Some don't invalidate at all (stale data after mutations)
- **Why it matters:** Stale data shown to users after actions.
- **Recommended refactor:** Define query key constants and standard invalidation patterns per domain.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## FE-11: Inconsistent Empty State Patterns

- **Severity:** Low
- **Category:** Consistency / UX
- **Affected files:** Table/list components across the codebase
- **Problem:** Some empty states show a centered message, some show an icon + message, some show nothing. No shared empty state component.
- **Why it matters:** Inconsistent user experience.
- **Recommended refactor:** Create shared `EmptyState` component with icon, title, and description props.
- **Suggested abstraction:** `resources/scripts/components/elements/EmptyState.tsx`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## FE-12: Store Memory Pattern (Easy-Peasy) Underutilized

- **Severity:** Low
- **Category:** Architecture
- **Affected files:** State management using `easy-peasy`
- **Problem:** The codebase uses `easy-peasy` for global state but many components manage their own local state for data that could benefit from shared state management (e.g., email settings, billing config, user permissions).
- **Why it matters:** Redundant API calls when navigating between components that need the same data.
- **Recommended refactor:** Evaluate which local state should be promoted to global state or handled via React-Query cache.
- **Estimated effort:** Medium
- **Risk of change:** Medium
