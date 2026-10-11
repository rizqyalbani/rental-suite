import { test, expect } from '@playwright/test';
import {
  clickSwalConfirmInPopup,
  dismissSwalOkIfVisible,
  gotoOutfitsIndexReady,
  fillCreateRentalPaymentIfNeeded,
  fillRentalDatesAndWaitAvailable,
  openShiftIfNeeded,
  waitForRentCreatedFeedback,
  waitForRentalSearchResults,
  waitForOutfitsAvailableAfterSearch,
  whenOutfitCreatePosts,
  whenRentalUpdatePosts,
} from './helpers';

test('create and edit rental flow works', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `E2E-${seed}`;
  const customerCreated = `Customer ${seed}`;
  const customerUpdated = `Customer Updated ${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('form').filter({
    has: page.getByRole('button', { name: 'Add Item' }),
  }).first();
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Playwright test item');
  const outfitCreatePost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  const outfitCreateRes = await outfitCreatePost;
  expect([200, 201, 302].includes(outfitCreateRes.status()), `outfit create HTTP ${outfitCreateRes.status()}`).toBeTruthy();
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  const rentalForm = page.locator('#rentalForm');
  const start = new Date();
  start.setDate(start.getDate() + 1);
  const end = new Date();
  end.setDate(end.getDate() + 3);
  const startDate = start.toISOString().slice(0, 10);
  const endDate = end.toISOString().slice(0, 10);

  await fillRentalDatesAndWaitAvailable(page, rentalForm, startDate, endDate);

  await expect(rentalForm.locator('#outfitSearch')).toBeEnabled();
  await waitForOutfitsAvailableAfterSearch(page, rentalForm, outfitCode);
  await expect(page.locator('#outfitList [data-id]:visible').first()).toBeVisible();
  await page.locator('#outfitList [data-id]:visible').first().click();

  await rentalForm.locator('input[name="customer_name"]').fill(customerCreated);
  await rentalForm.locator('input[name="phone"]').fill('081234567890');
  await rentalForm.locator('input[name="address"]').fill('E2E Address');
  await fillCreateRentalPaymentIfNeeded(rentalForm);
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();

  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  await waitForRentalSearchResults(page, customerCreated);
  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();
  await expect(page.locator('#editCartCount')).not.toHaveText('0');
  await expect(page.locator('#editOutfitCart')).toContainText(outfitCode);

  await page.locator('#editCust').fill(customerUpdated);
  const updatePost = whenRentalUpdatePosts(page);
  await page.locator('#editForm button:has-text("Save Changes")').click();
  await clickSwalConfirmInPopup(page, 'Save');
  await updatePost;
  await dismissSwalOkIfVisible(page);

  await expect(page.locator('#editModal')).toBeHidden({ timeout: 15_000 });
  await waitForRentalSearchResults(page, customerUpdated);
  await expect(page.locator('#rentalCards')).toContainText(customerUpdated);
});

test('quick add enter key does not submit rental form', async ({ page }) => {
  const seed = Date.now();
  const quickCode = `QADD-${seed}`;

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  await page.getByRole('button', { name: /Quick add new item code/i }).click();
  const quickAddInput = page.locator('#quickAddCreateInput');
  await quickAddInput.fill(quickCode);
  await quickAddInput.press('Enter');

  await expect(page).toHaveURL(/\/rentals/);
  await expect(page.locator('body')).not.toContainText('Rent created successfully');
  await expect(page.locator('#quickAddCreateInput')).toHaveValue('');
});

test('outfits edit stock enter key does not submit update form', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `STOCK-${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Stock enter test');
  await createOutfitForm.locator('input[name="stock"]').fill('2');
  const createPost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  {
    const res = await createPost;
    expect([200, 201, 302].includes(res.status()), `outfit create HTTP ${res.status()}`).toBeTruthy();
  }
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);

  const row = page.locator('tr', { hasText: outfitCode }).first();
  await row.getByRole('button', { name: 'Edit' }).click();
  await expect(page.locator('#editModal')).toBeVisible();

  const editStock = page.locator('#editStock');
  await editStock.fill('5');
  await editStock.press('Enter');

  await expect(page.locator('#editModal')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/Outfit updated successfully/i);
});

test('outfits stock update persists and does not pollute create form', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `UPDSTOCK-${seed}`;
  const updatedStock = '7';

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Stock persistence test');
  await createOutfitForm.locator('input[name="stock"]').fill('2');
  const createPost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  {
    const res = await createPost;
    expect([200, 201, 302].includes(res.status()), `outfit create HTTP ${res.status()}`).toBeTruthy();
  }
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);

  const row = page.locator('tr', { hasText: outfitCode }).first();
  await expect(row).toContainText(/\b2\b/);
  await row.getByRole('button', { name: 'Edit' }).click();
  await expect(page.locator('#editModal')).toBeVisible();
  await page.locator('#editStock').fill(updatedStock);
  const updatePost = page.waitForResponse(
    (res) => {
      if (res.request().method() !== 'POST') return false;
      try {
        const p = new URL(res.url()).pathname;
        return /^\/outfits\/\d+$/.test(p);
      } catch {
        return false;
      }
    },
    { timeout: 25_000 },
  );
  await page.locator('#editForm button:has-text("Update")').click();
  await clickSwalConfirmInPopup(page, 'Save');
  const updateRes = await updatePost;
  expect([200, 201, 302].includes(updateRes.status()), `outfit update HTTP ${updateRes.status()}`).toBeTruthy();
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);
  await expect(page.locator('tr', { hasText: outfitCode }).first()).toContainText(new RegExp(`\\b${updatedStock}\\b`));

  await expect(page.locator('#createOutfitForm input[name="code"]')).toHaveValue('');
  await expect(page.locator('#createOutfitForm input[name="new_code"]')).toHaveValue('');
  await expect(page.locator('#createOutfitForm input[name="description"]')).toHaveValue('');
});

test('edit modal can switch rented item from all tab', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `SWITCH-${seed}`;
  const auxCode = `AUX-${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('Switch test');
  await createOutfitForm.locator('input[name="stock"]').fill('1');
  const createPost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  {
    const res = await createPost;
    expect([200, 201, 302].includes(res.status()), `outfit create HTTP ${res.status()}`).toBeTruthy();
  }
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);
  await createOutfitForm.locator('input[name="code"]').fill(auxCode);
  await createOutfitForm.locator('input[name="description"]').fill('Aux test');
  await createOutfitForm.locator('input[name="stock"]').fill('1');
  const createPost2 = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  {
    const res = await createPost2;
    expect([200, 201, 302].includes(res.status()), `outfit create HTTP ${res.status()}`).toBeTruthy();
  }
  await dismissSwalOkIfVisible(page);
  await gotoOutfitsIndexReady(page);

  await page.goto('/rentals');
  await openShiftIfNeeded(page);
  const rentalForm = page.locator('#rentalForm');
  const start = new Date();
  start.setDate(start.getDate() + 1);
  const end = new Date();
  end.setDate(end.getDate() + 3);
  const startDate = start.toISOString().slice(0, 10);
  const endDate = end.toISOString().slice(0, 10);

  await fillRentalDatesAndWaitAvailable(page, rentalForm, startDate, endDate);
  await waitForOutfitsAvailableAfterSearch(page, rentalForm, outfitCode);
  await expect(page.locator('#outfitList [data-id]:visible').first()).toBeVisible();
  await page.locator('#outfitList [data-id]:visible').first().click();
  await rentalForm.locator('input[name="customer_name"]').fill(`First ${seed}`);
  await rentalForm.locator('input[name="phone"]').fill('081777777777');
  await rentalForm.locator('input[name="address"]').fill('Addr 1');
  await fillCreateRentalPaymentIfNeeded(rentalForm);
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();
  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  await fillRentalDatesAndWaitAvailable(page, rentalForm, startDate, endDate);
  await waitForOutfitsAvailableAfterSearch(page, rentalForm, auxCode);
  await expect(page.locator('#outfitList [data-id]:visible').first()).toBeVisible();
  await page.locator('#outfitList [data-id]:visible').first().click();
  await rentalForm.locator('input[name="customer_name"]').fill(`Second ${seed}`);
  await rentalForm.locator('input[name="phone"]').fill('081888888888');
  await rentalForm.locator('input[name="address"]').fill('Addr 2');
  await fillCreateRentalPaymentIfNeeded(rentalForm);
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();
  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  await waitForRentalSearchResults(page, `Second ${seed}`);
  await dismissSwalOkIfVisible(page);
  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();
  await expect(page.locator('#editCartCount')).not.toHaveText('0');

  await page.getByRole('button', { name: 'All Items' }).click();
  await page.locator('#editOutfitSearch').fill(outfitCode);
  await expect(page.locator('#editOutfitListInner button:has-text("Switch This Item"):visible').first()).toBeVisible();
});
