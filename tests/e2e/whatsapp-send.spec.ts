import { test, expect } from '@playwright/test';
import {
  dismissSwalOkIfVisible,
  fillCreateRentalPaymentIfNeeded,
  fillRentalDatesAndWaitAvailable,
  gotoOutfitsIndexReady,
  openShiftIfNeeded,
  waitForRentCreatedFeedback,
  waitForRentalSearchResults,
  waitForOutfitsAvailableAfterSearch,
  whenOutfitCreatePosts,
} from './helpers';

test('send invoice via WhatsApp from rentals page', async ({ page }) => {
  const seed = Date.now();
  const outfitCode = `WA-E2E-${seed}`;
  const customer = `WA Customer ${seed}`;

  await gotoOutfitsIndexReady(page);
  const createOutfitForm = page.locator('#createOutfitForm');
  await createOutfitForm.locator('input[name="code"]').fill(outfitCode);
  await createOutfitForm.locator('input[name="description"]').fill('WA E2E item');
  const createPost = whenOutfitCreatePosts(page);
  await page.getByRole('button', { name: 'Add Item' }).click();
  {
    const res = await createPost;
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
  await rentalForm.locator('input[name="customer_name"]').fill(customer);
  await rentalForm.locator('input[name="phone"]').fill('081234567890');
  await rentalForm.locator('input[name="address"]').fill('WA E2E Address');
  await fillCreateRentalPaymentIfNeeded(rentalForm);
  await rentalForm.getByRole('button', { name: 'Create Rent' }).click();
  await waitForRentCreatedFeedback(page);
  await dismissSwalOkIfVisible(page);

  await waitForRentalSearchResults(page, customer);
  await expect(page.locator('#rentalCards button:has-text("Edit")').first()).toBeVisible();

  await page.locator('#rentalCards button:has-text("Edit")').first().click();
  await expect(page.locator('#editModal')).toBeVisible();

  await page.locator('#editForm button:has-text("Send via WhatsApp")').click();
  const responsePromise = page.waitForResponse((response) => response.url().includes('/invoice/send-whatsapp'), {
    timeout: 30_000,
  });
  await page.getByRole('dialog').getByRole('button', { name: 'Send' }).click();
  const sendResponse = await responsePromise;
  const status = sendResponse.status();
  const body = await sendResponse.text().catch(() => '');
  expect(sendResponse.ok(), `send-whatsapp HTTP ${status}: ${body}`).toBeTruthy();
});
