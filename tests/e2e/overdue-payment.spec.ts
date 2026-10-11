import { test, expect } from '@playwright/test';
import {
  clickSwalConfirmInPopup,
  dismissSwalOkIfVisible,
  fillRentalDatesAndWaitAvailable,
  gotoOutfitsIndexReady,
  openShiftIfNeeded,
  waitForRentCreatedFeedback,
  waitForRentalSearchResults,
  waitForOutfitsAvailableAfterSearch,
  whenOutfitCreatePosts,
  whenRentalUpdatePosts,
} from './helpers';

test('overdue charge can be applied and paid separately', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `E2E-OVD-${seed}`;
  const customerName = `Overdue Customer ${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Overdue payment test item');
  await createOutfitForm.locator('input[name="price"]').fill('100000');
  const outfitCreatePost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  const outfitCreateRes = await outfitCreatePost;
  expect([200, 201, 302].includes(outfitCreateRes.status()), `outfit create HTTP ${outfitCreateRes.status()}`).toBeTruthy();
  await dismissSwalOkIfVisible(page);

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  const rentalForm = page.locator('#rentalForm');
  const start = new Date();
  start.setDate(start.getDate() + 1);
  const startDate = start.toISOString().slice(0, 10);
  const endDate = startDate;

  await fillRentalDatesAndWaitAvailable(page, rentalForm, startDate, endDate);
  await waitForOutfitsAvailableAfterSearch(page, rentalForm, outfitCode);
  await page.locator('#outfitList [data-id]:visible').first().click();
  await rentalForm.locator('input[name="customer_name"]').fill(customerName);
  await rentalForm.locator('input[name="phone"]').fill('081234567890');
  await rentalForm.locator('input[name="address"]').fill('E2E Address');
  await rentalForm.locator('select[name="payment_method"]').first().selectOption('cash');
  await rentalForm.locator('input[name="payment_kind"][value="full"]').first().check();
  await rentalForm.locator('#amountPaid').fill('100000');
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();
  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  const debugDate = new Date();
  debugDate.setDate(debugDate.getDate() + 2);
  const debugYmd = debugDate.toISOString().slice(0, 10);
  await page.goto(`/rentals?debug_today=${encodeURIComponent(debugYmd)}`);
  await openShiftIfNeeded(page);

  await waitForRentalSearchResults(page, customerName);
  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();

  await expect(page.locator('#editOverdueReturnPanel')).toBeVisible();
  await expect(page.locator('#editAmountPaid')).toHaveValue('100.000');
  await page.locator('#editApplyOverdueChargeBtn').click();
  await expect(page.locator('#editAmountPaid')).toHaveValue('100.000');

  await expect(page.locator('#editOverduePayPanel')).toBeVisible();
  await page.locator('#editOverduePaymentMethod').selectOption('cash');
  await page.locator('#editOverdueAmountPaid').fill('200000');

  const payPost = whenRentalUpdatePosts(page);
  await page.locator('#editPayOverdueBtn').click();
  await clickSwalConfirmInPopup(page, 'Pay');
  const payRes = await payPost;
  const payJson = await payRes.json().catch(() => ({}));
  expect(payRes.ok(), `overdue pay HTTP ${payRes.status()} ${JSON.stringify(payJson)}`).toBeTruthy();
  expect(payJson && typeof payJson === 'object' && 'ok' in payJson ? (payJson as any).ok : true).toBeTruthy();
  await dismissSwalOkIfVisible(page);
  await dismissSwalOkIfVisible(page);

  await expect(page.locator('#editOverduePayPanel')).toBeHidden({ timeout: 30_000 });
  await expect(page.locator('#editOverdueInfo')).toContainText('Settled', { timeout: 30_000 });
  await expect(page.locator('#editOverdueInfo')).toContainText('Change', { timeout: 30_000 });
  await expect(page.locator('#editChangeMoneyInDuePanel')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#editChangeMoneyAmount')).toContainText('100.000', { timeout: 30_000 });
  await expect(page.locator('#editCustomerPaidPanel')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#editCustomerPaidAmount')).toContainText('300.000', { timeout: 30_000 });

  const debugLater = new Date(debugDate);
  debugLater.setDate(debugLater.getDate() + 1);
  const debugLaterYmd = debugLater.toISOString().slice(0, 10);
  await page.goto(`/rentals?debug_today=${encodeURIComponent(debugLaterYmd)}`);
  await openShiftIfNeeded(page);

  await waitForRentalSearchResults(page, customerName);
  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();
  await expect(page.locator('#editOverdueInfo')).toContainText('Additional charge available', { timeout: 30_000 });

  await page.locator('#editApplyOverdueChargeBtn').click();
  await expect(page.locator('#editOverduePayPanel')).toBeVisible({ timeout: 30_000 });
  await page.locator('#editOverduePaymentMethod').selectOption('cash');
  await page.locator('#editOverdueAmountPaid').fill('100000');

  const payPost2 = whenRentalUpdatePosts(page);
  await page.locator('#editPayOverdueBtn').click();
  await clickSwalConfirmInPopup(page, 'Pay');
  const payRes2 = await payPost2;
  const payJson2 = await payRes2.json().catch(() => ({}));
  expect(payRes2.ok(), `additional overdue pay HTTP ${payRes2.status()} ${JSON.stringify(payJson2)}`).toBeTruthy();
  expect(payJson2 && typeof payJson2 === 'object' && 'ok' in payJson2 ? (payJson2 as any).ok : true).toBeTruthy();
  await dismissSwalOkIfVisible(page);
  await dismissSwalOkIfVisible(page);

  await expect(page.locator('#editOverduePayPanel')).toBeHidden({ timeout: 30_000 });
  await expect(page.locator('#editOverdueInfo')).toContainText('Settled', { timeout: 30_000 });
});

test('returned rent is locked (amount paid not editable)', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `E2E-RET-${seed}`;
  const customerName = `Return Customer ${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Returned amount paid editable');
  await createOutfitForm.locator('input[name="price"]').fill('15000');
  const outfitCreatePost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  const outfitCreateRes = await outfitCreatePost;
  expect([200, 201, 302].includes(outfitCreateRes.status()), `outfit create HTTP ${outfitCreateRes.status()}`).toBeTruthy();
  await dismissSwalOkIfVisible(page);

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  const rentalForm = page.locator('#rentalForm');
  const start = new Date();
  start.setDate(start.getDate() + 1);
  const startDate = start.toISOString().slice(0, 10);
  const endDate = startDate;

  await fillRentalDatesAndWaitAvailable(page, rentalForm, startDate, endDate);
  await waitForOutfitsAvailableAfterSearch(page, rentalForm, outfitCode);
  await page.locator('#outfitList [data-id]:visible').first().click();
  await rentalForm.locator('input[name="customer_name"]').fill(customerName);
  await rentalForm.locator('input[name="phone"]').fill('081234567891');
  await rentalForm.locator('input[name="address"]').fill('E2E Address');
  await rentalForm.locator('select[name="payment_method"]').first().selectOption('cash');
  await rentalForm.locator('input[name="payment_kind"][value="full"]').first().check();
  await rentalForm.locator('#amountPaid').fill('20000');
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();
  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  await waitForRentalSearchResults(page, customerName);

  await page.locator('#rentalCards button:has-text("Return")').first().click();
  await clickSwalConfirmInPopup(page, 'Yes, return');
  await dismissSwalOkIfVisible(page);
  await page.getByText('Returned').first().waitFor({ state: 'visible', timeout: 30_000 });

  await waitForRentalSearchResults(page, customerName);
  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();
  const amt = page.locator('#editAmountPaid');
  await expect(amt).toBeDisabled();
  await expect(amt).toHaveValue('20.000');
  await expect(page.locator('#editSaveChangesBtn')).toBeHidden();
});
