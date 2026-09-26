import { expect, test } from '@playwright/test';

/**
 * The console gate.
 *
 * PHASE-PLAN.md Part C requires zero console errors AND zero warnings before a
 * task is done — a 404 asset counts, a Livewire or Alpine warning counts. This
 * file is the enforcement, so the bar does not depend on anyone remembering.
 *
 * Add a page here whenever a task adds a Filament screen.
 */

/** Collect console messages and failed responses for one page visit. */
function watch(page) {
  const problems = [];

  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error' || type === 'warning') {
      problems.push(`console.${type}: ${msg.text()}`);
    }
  });

  page.on('pageerror', (err) => {
    problems.push(`pageerror: ${err.message}`);
  });

  page.on('response', (res) => {
    if (res.status() >= 400) {
      problems.push(`HTTP ${res.status()}: ${res.url()}`);
    }
  });

  return problems;
}


test('login page has a clean console', async ({ page, browser }) => {
  // The one page that must be seen signed OUT, so it gets its own context
  // rather than the shared authenticated session.
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  page = await anonymous.newPage();
  const problems = watch(page);

  await page.goto('/admin/login');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page).toHaveTitle(/Login/);
});

test('dashboard has a clean console after sign-in', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page).toHaveTitle(/Dashboard/);
});

test('approval matrix list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/approval-matrices');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // The seeded matrix should be on screen, not an empty state — a clean console
  // on a page that failed to load anything proves nothing.
  await expect(page.getByText('purchase_order').first()).toBeVisible();
});

test('approval matrix create form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/approval-matrices/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // A label this form defines itself, rather than a guessed input id — it
  // proves our fields rendered, not merely that some Filament page loaded.
  await expect(page.getByText('Band floor')).toBeVisible();
});

test('approvals inbox has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/approvals');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // The seeded admin holds no approver roles, so the correct state here is the
  // empty one — and an inbox that renders its empty state is still an inbox
  // that rendered.
  await expect(page.getByText('Nothing waiting on you')).toBeVisible();
});

test('vendor register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/vendors');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);

  // Assert on the seeded rows rather than on a column label. The label also
  // appears in the column-toggle menu, and more importantly a header proves
  // only that a table rendered — these prove the register rendered its data.
  await expect(page.getByRole('columnheader', { name: 'RFQ eligible' })).toBeVisible();
  await expect(page.getByText('Northgate Cement Supply')).toBeVisible();
  await expect(page.getByText('Sunrise Aggregates')).toBeVisible();
});

/*
 * The Phase 1 chain screens — P1-16.
 *
 * Each asserts on a value the DEMO CHAIN produced, not on a column header. A
 * header proves a table rendered; a seeded document number proves the screen
 * read the chain the services actually built. The seed is deliberately not a
 * tidy one — the delivery was short and part of it was rejected — so several of
 * these assert on the failure being visible rather than on everything looking
 * fine.
 */

test('requisitions list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/purchase-requisitions');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('PR-2026-00001')).toBeVisible();
  await expect(page.getByText('MBI-2026-014').first()).toBeVisible();
});

test('rfq list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/rfqs');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('RFQ-2026-00001')).toBeVisible();
});

test('purchase orders list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/purchase-orders');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('PO-2026-00001')).toBeVisible();
  // Twenty bags never arrived, so the order is not closed.
  await expect(page.getByText('20.0000')).toBeVisible();
});

test('receiving list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/receiving-reports');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('RR-2026-00001')).toBeVisible();
  await expect(page.getByText('DR-88214')).toBeVisible();
  // The exit gate asks for a short delivery noted on the DR — this is the
  // column that notes it.
  await expect(page.getByRole('columnheader', { name: 'Short' })).toBeVisible();
});

test('stock cards list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/stock-cards');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // 460 accepted less 200 issued. Both figures are summed from movements, so a
  // visible balance means the card was read as a ledger and not as a column.
  await expect(page.getByText('260.0000')).toBeVisible();
});

test('three-way match list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/three-way-matches');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('TWM-2026-00001')).toBeVisible();
  await expect(page.getByText('INV-2026-4471')).toBeVisible();
});

test('ap vouchers list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/ap-vouchers');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('APV-2026-00001')).toBeVisible();
  // Gross less withholding less the advance already released.
  await expect(page.getByText('₱88,394.60')).toBeVisible();
});

test('equipment register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/equipment');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('EQ-EXC-001')).toBeVisible();
  // The rented generator is on the register too, and it is the row that must
  // never acquire a depreciation schedule.
  await expect(page.getByText('EQ-GEN-004')).toBeVisible();
});

test('scorecards list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/vendor-scorecards');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // All four of F12's dimensions, by name. Three would be slide 4's version.
  await expect(page.getByRole('columnheader', { name: 'Price' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Delivery' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Quality' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Documents' })).toBeVisible();
});

test('project cost ledger has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/ledger');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // Phase 1's exit gate, on screen: material cost in the ledger, carrying the
  // project code and cost code it was posted against.
  await expect(page.getByText('MI-2026-00001')).toBeVisible();
  await expect(page.getByText('02.10.140')).toBeVisible();
  await expect(page.getByText('₱49,800.00')).toBeVisible();
});

/*
 * The Phase 2 screens — P2-10.
 *
 * Each asserts on something the demo chain actually produced. The billing pair
 * is the one that matters: one approved, one RETURNED with a deducted line, so
 * the screen proves the branch PHASE-PLAN.md calls first-class is visible rather
 * than merely implemented.
 */

test('accomplishments list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/accomplishments');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('ACC-2026-00001')).toBeVisible();
  // Both signature columns. Joint means both, and a single column could not
  // show the ordinary state where only the contractor has signed.
  await expect(page.getByRole('columnheader', { name: 'Contractor' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Client' })).toBeVisible();
});

test('billings list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/billings');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('BILL-2026-00001')).toBeVisible();
  // The returned one, and the reason the client gave for sending it back.
  await expect(page.getByText('BILL-2026-00002')).toBeVisible();
  // Scoped to the table: the status filter's dropdown carries the same word,
  // and a selector that matches both proves only that the page has a filter.
  await expect(page.getByRole('table').getByText('Returned', { exact: true })).toBeVisible();
});

test('sales invoices list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/sales-invoices');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('SI-2026-00001')).toBeVisible();
  // Gross less retention less withholding — the figure the client actually
  // remits, and the one the AR sweep chases.
  await expect(page.getByText('₱12,804,000.00')).toBeVisible();
});

test('ar escalations list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/ar-escalations');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // F13's named recipient. An escalation addressed to nobody is a status change.
  await expect(page.getByText('project-manager')).toBeVisible();
});

test('mobilization list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/mobilizations');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('MOB-2026-00001')).toBeVisible();
  // The countersigned order it mobilized against — F2's gate, on screen.
  await expect(page.getByText('PO-2026-00001')).toBeVisible();
});

test('permits list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/permits');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('BP-2026-0114')).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'In force' })).toBeVisible();
});

/*
 * The Phase 3 screens — P3-12.
 *
 * The payroll chain is the one where a screen leaking is worst, so two of these
 * assert on what is NOT rendered: the employee register must not print a
 * government number or a rate, and the payroll register must not print a
 * per-employee figure. A clean console on a page that shows too much is not a
 * passing test.
 */

test('employees register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/employees');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('EMP-0001')).toBeVisible();

  // Encrypted at rest, and absent from the screen. The seeded TIN, SSS number
  // and daily rate must appear nowhere in the rendered page.
  const body = await page.locator('body').innerText();
  expect(body).not.toContain('123-456-789-000');
  expect(body).not.toContain('34-5678901-2');
  expect(body).not.toContain('1200.0000');
});

test('daily time records list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/daily-time-records');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // The held day, visible as a state rather than hidden as an error.
  await expect(page.getByRole('table').getByText('Held', { exact: true })).toBeVisible();
});

test('overtime authorities list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/overtime-authorities');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // Slide 7's "in writing" — the memo reference the approval rests on.
  await expect(page.getByText('SE memo', { exact: false })).toBeVisible();
});

test('payroll runs list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/payroll-runs');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('PAY-2026-00001')).toBeVisible();
  // Totals across everybody reveal nobody's pay; a per-line figure would.
  await expect(page.getByText('₱24,525.00')).toBeVisible();
});

test('disbursements list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/disbursements');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('DSB-2026-00001')).toBeVisible();
  // The bank half's evidence: the reference that came back.
  await expect(page.getByText('BDO-BATCH-77341')).toBeVisible();
});

/*
 * The Phase 4 screens — P4-10.
 *
 * The demo month is deliberately left AT BUDGET REVIEW with an unexplained
 * variance, so two of these assert the blocked state rather than a clean one: a
 * month that closed without incident would show none of what Phase 4 built.
 */

test('expenses list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/expenses');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('EXP-2026-00001')).toBeVisible();
  // The returned one, with the reason the site has to correct against.
  await expect(page.getByRole('table').getByText('Returned', { exact: true })).toBeVisible();
  await expect(page.getByText('Batangas', { exact: false })).toBeVisible();
});

test('cash advances list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/cash-advances');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('CA-2026-00001')).toBeVisible();
  // Swept at cutoff — the state somebody needs to see before their payslip
  // tells them. Scoped to tbody: "Charged to payroll" is also a column header,
  // and a selector matching both proves only that the column exists.
  await expect(page.locator('tbody').getByText('Charged to payroll', { exact: true })).toBeVisible();
});

test('opex calendar has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/opex-periods');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // Slide 8's day 30, and the month held there by the unexplained variance.
  await expect(page.getByText('Budget review (day 30)')).toBeVisible();
});

test('payroll deductions list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/payroll-deductions');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // F17's cross-chain write, visible to the HR clerk before the employee sees
  // it on a payslip. Exact matching: the advance number also appears inside the
  // reason sentence, and a loose match would pass on that alone.
  await expect(page.getByText('CA-2026-00001', { exact: true })).toBeVisible();
  await expect(page.getByText('₱1,600.00')).toBeVisible();
});

test('project P and L page has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/project-profit-and-loss');
  await page.waitForLoadState('networkidle');

  await expect(page.getByText('Gross profit').first()).toBeVisible();
  // F16's output, beside the P&L rather than inside it. The heading is constant;
  // the total below it reads "Net surplus" when collections exceed outgoings.
  await expect(page.getByText('Cash requirement').first()).toBeVisible();
  await expect(page.getByText(/Net (requirement|surplus)/)).toBeVisible();
  // Reported, and deliberately outside the net: held until the defects period ends.
  await expect(page.getByText('Retention held')).toBeVisible();

  // The period control. The page used to compute a month and offer no way to
  // change it, so the question it exists to answer could not be asked.
  await expect(page.getByRole('button', { name: 'Previous' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Next' })).toBeVisible();

  // Asserted last, so a render failure is reported as the console error it is
  // rather than as a missing heading.
  expect(problems, problems.join('\n')).toEqual([]);
});

/*
 * Phase 5 — close-out. Eight resources and one report page.
 *
 * The demo seeder does not run a close-out (a project cannot reach one without
 * a defects liability period elapsing, and the demo data is dated this year),
 * so these assert the empty state renders cleanly rather than asserting on
 * rows. That is still the check that matters here: an empty Filament table with
 * a broken column callback throws in the browser and nowhere else.
 */

test('punchlists list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/punchlists');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Punchlists').first()).toBeVisible();
});

test('back-charges list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/back-charges');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Back-charges').first()).toBeVisible();
});

test('warranties list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/warranties');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Warranties').first()).toBeVisible();
});

test('turnover packs list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/turnover-packs');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Turnover packs').first()).toBeVisible();
});

test('demobilizations list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/demobilizations');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Demobilizations').first()).toBeVisible();
});

test('retention releases list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/retention-releases');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Retention releases').first()).toBeVisible();
});

test('final accounts list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/final-accounts');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Final accounts').first()).toBeVisible();
});

test('close-out checklists list has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/close-out-checklists');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Close-out checklists').first()).toBeVisible();
});

test('add employee form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/employees/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  // The write-only government number section, which is the part of this form
  // most likely to break on render.
  await expect(page.getByText('Government numbers').first()).toBeVisible();
});

test('open project form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/projects/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Project code').first()).toBeVisible();
});

test('add cost code form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/cost-codes/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Parent').first()).toBeVisible();
});

test('draft budget form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/budgets/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Budget').first()).toBeVisible();
});

test('register equipment form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/equipment/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Acquisition and depreciation').first()).toBeVisible();
});

test('add account form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/users/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Roles').first()).toBeVisible();
});

test('13th month report has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/thirteenth-month-pay');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('13th month pay').first()).toBeVisible();
});

test('vendor advances register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/vendor-advances');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Vendor advances').first()).toBeVisible();
});

test('draft contract form has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/contracts/create');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Contract sum').first()).toBeVisible();
});

test('subcontracts register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/subcontracts');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
  await expect(page.getByText('Subcontracts').first()).toBeVisible();
});

test('dashboard KPI widgets have a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin');
  await page.waitForLoadState('networkidle');

  // Filament widgets are lazy: the first response carries a placeholder and the
  // content arrives when the widget enters the viewport. Scrolling to the bottom
  // is what a reader does, and it is what makes each chart's Chart.js actually
  // run — which is the JS this gate exists to catch.
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForLoadState('networkidle');

  await expect(page.getByText('Needs attention').first()).toBeVisible();
  await expect(page.getByText('Revenue and cost to date').first()).toBeVisible();
  await expect(page.getByText('Budget versus actual').first()).toBeVisible();

  // Asserted last, so a chart that throws on render is reported as the console
  // error it is rather than as a missing heading.
  expect(problems, problems.join('\n')).toEqual([]);
});
