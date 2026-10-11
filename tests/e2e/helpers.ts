import type { Page } from '@playwright/test';

async function ensureLoggedIn(page: Page) {
  const email = page.locator('input[name="login"]');
  if (!(await email.isVisible({ timeout: 800 }).catch(() => false))) {
    return;
  }
  const password = page.locator('input[name="password"]');
  const userEmail = process.env.PLAYWRIGHT_E2E_EMAIL || 'superadmin@rental.test';
  const userPass = process.env.PLAYWRIGHT_E2E_PASSWORD || 'password';
  await email.fill(userEmail);
  await password.fill(userPass);
  const nav = page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20_000 }).catch(() => null);
  await page.getByRole('button', { name: 'Log in' }).click().catch(() => password.press('Enter'));
  await nav;
  await page.waitForLoadState('domcontentloaded').catch(() => {});
}

export async function openShiftIfNeeded(page: Page) {
  await ensureLoggedIn(page);
  if (page.url().includes('/login')) {
    await page.goto('/rentals');
    await page.waitForLoadState('domcontentloaded');
    await ensureLoggedIn(page);
  }
  const btn = page.getByRole('button', { name: 'Open Shift' });
  if (await btn.isVisible()) {
    await btn.click();
  }
}

/** Outfits index can show a blocking session-flash dialog (z-index over the form). */
export async function dismissOutfitsSessionFlashIfPresent(page: Page) {
  const ok = page.locator('#outfitsSessionFlashModalOk');
  if (await ok.isVisible({ timeout: 2500 }).catch(() => false)) {
    await ok.click();
    await page.locator('#outfitsSessionFlashModal').waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
  }
}

/** Navigate to Items and clear modals that block the create form (session flash + stray Swal). */
export async function gotoOutfitsIndexReady(page: Page) {
  await page.goto('/outfits');
  await page.waitForLoadState('domcontentloaded');
  await ensureLoggedIn(page);
  if (page.url().includes('/login')) {
    await page.goto('/outfits');
    await page.waitForLoadState('domcontentloaded');
  }
  if (!page.url().includes('/outfits')) {
    const nav = page.waitForURL((u) => u.pathname.includes('/outfits'), { timeout: 20_000 }).catch(() => null);
    await page.getByRole('link', { name: 'Items' }).click().catch(() => {});
    await nav;
  }
  await dismissOutfitsSessionFlashIfPresent(page);
  for (let i = 0; i < 4; i++) {
    const confirm = page.locator('.swal2-container .swal2-confirm');
    if (!(await confirm.isVisible({ timeout: 400 }).catch(() => false))) {
      break;
    }
    await confirm.click();
    await page.locator('.swal2-container').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  }
  await page.locator('#createOutfitForm input[name="code"]').waitFor({ state: 'visible', timeout: 20_000 });
}

/** SweetAlert2 success/error — UI continues (reload / loadRentals) after OK. */
export async function dismissSwalOkIfVisible(page: Page) {
  for (let i = 0; i < 6; i++) {
    const confirm = page.locator('.swal2-container .swal2-confirm');
    if (!(await confirm.isVisible({ timeout: 600 }).catch(() => false))) {
      break;
    }
    await confirm.click({ force: true }).catch(() => {});
    await page.locator('.swal2-container').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
  }

  const container = page.locator('.swal2-container');
  if (await container.isVisible({ timeout: 300 }).catch(() => false)) {
    await page.keyboard.press('Escape').catch(() => {});
    await container.waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
  }
}

export async function clickSwalConfirmInPopup(page: Page, buttonName: string) {
  const popup = page.locator('.swal2-popup');
  await popup.waitFor({ state: 'visible', timeout: 15_000 });
  await popup.getByRole('button', { name: buttonName }).click();
}

/** Create-item success is shown in SweetAlert, not always as plain body text. */
export async function waitForOutfitCreatedFeedback(page: Page) {
  const swalConfirm = page.locator('.swal2-container .swal2-confirm');
  if (await swalConfirm.isVisible({ timeout: 20_000 }).catch(() => false)) {
    return;
  }
  await page.getByText(/Item (added|created) successfully/i).first().waitFor({ state: 'visible', timeout: 20_000 });
}

/** Matches app copy: "Rent created successfully." */
export async function waitForRentCreatedFeedback(page: Page) {
  await page.getByText(/Rent created successfully/i).first().waitFor({ state: 'visible', timeout: 20_000 });
}

/** When pricing is enabled, server requires payment_method, payment_kind, amount_paid. */
export async function fillCreateRentalPaymentIfNeeded(rentalForm: ReturnType<Page['locator']>) {
  const amount = rentalForm.locator('#amountPaid');
  if (!(await amount.isVisible().catch(() => false))) {
    return;
  }
  await rentalForm.locator('select[name="payment_method"]').first().selectOption('cash');
  await rentalForm.locator('input[name="payment_kind"][value="full"]').first().check();
  await amount.fill('250000');
}

export async function fillRentalDatesAndWaitAvailable(
  page: Page,
  rentalForm: ReturnType<Page['locator']>,
  startDate: string,
  endDate: string,
) {
  await rentalForm.locator('#rsd').fill(startDate);
  const respPromise = page.waitForResponse(
    (res) => res.url().includes('/outfits/available') && res.ok(),
    { timeout: 25_000 },
  );
  await rentalForm.locator('#red').fill(endDate);
  await respPromise;
}

/** Wait for availability API after search (avoids racing the outfit list render). */
export async function waitForOutfitsAvailableAfterSearch(
  page: Page,
  rentalForm: ReturnType<Page['locator']>,
  outfitCode: string,
) {
  const respPromise = page.waitForResponse(
    (res) => res.url().includes('/outfits/available') && res.ok(),
    { timeout: 25_000 },
  );
  await rentalForm.locator('#outfitSearch').fill(outfitCode);
  await respPromise;
}

export async function waitForRentalSearchResults(page: Page, query: string) {
  const respPromise = page.waitForResponse(
    (res) => res.url().includes('/rentals/search') && res.ok(),
    { timeout: 20_000 },
  );
  await page.locator('#searchInput').fill(query);
  await respPromise;
}

export function whenRentalUpdatePosts(page: Page) {
  return page.waitForResponse(
    (res) => {
      if (res.request().method() !== 'POST') return false;
      try {
        const p = new URL(res.url()).pathname;
        return /^\/rentals\/\d+$/.test(p);
      } catch {
        return false;
      }
    },
    { timeout: 25_000 },
  );
}

export function whenOutfitCreatePosts(page: Page) {
  return page.waitForResponse(
    (res) => {
      if (res.request().method() !== 'POST') return false;
      try {
        const p = new URL(res.url()).pathname;
        return p === '/outfits';
      } catch {
        return false;
      }
    },
    { timeout: 25_000 },
  );
}
