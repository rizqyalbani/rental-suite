{{-- Shared "walk the real New Rent form" steps — included once for Admin and once for
     Supervisor/Management, since both create rentals through the exact same form.
     All readonly (data-target-readonly): every one of these highlights a real form
     section, but Next/Back drive it — nothing here submits or mutates data.
     data-tutorial-form="rentalForm" tags the real create-rent form with a hidden
     tutorial_practice flag for as long as one of these steps is active — see
     applyTutorialFormFlag() — so the real rental this creates is marked is_tutorial
     and stays out of real lists/reports instead of mixing in with production data. --}}
<div class="rental-tour-step hidden" data-target="#newRentStepDates" data-target-readonly="1" data-require="dates" data-tutorial-form="rentalForm">
    <h3 class="text-lg font-semibold text-gray-900 mb-2">1. Fill in the dates first</h3>
    <p class="text-sm text-gray-600 leading-relaxed">
        <strong>Fill in the start & end dates.</strong> The item choices appear after this.
    </p>
</div>

<div class="rental-tour-step hidden" data-target="#outfitList" data-target-readonly="1" data-require="items" data-tutorial-form="rentalForm">
    <h3 class="text-lg font-semibold text-gray-900 mb-2">2. Choose items</h3>
    <p class="text-sm text-gray-600 leading-relaxed">
        <strong>Add at least 1 item</strong> from the list here.
    </p>
</div>

<div class="rental-tour-step hidden" data-target="#newRentStepPayment" data-target-readonly="1" data-require="payment" data-tutorial-form="rentalForm">
    <h3 class="text-lg font-semibold text-gray-900 mb-2">3. Set up payment</h3>
    <p class="text-sm text-gray-600 leading-relaxed">
        <strong>Choose the payment method</strong>: full payment or deposit (DP).
    </p>
</div>

<div class="rental-tour-step hidden" data-target="#newRentStepCustomer" data-target-readonly="1" data-require="customer" data-tutorial-form="rentalForm">
    <h3 class="text-lg font-semibold text-gray-900 mb-2">4. Customer details</h3>
    <p class="text-sm text-gray-600 leading-relaxed">
        <strong>Enter the customer's name & phone number.</strong>
    </p>
</div>

<div class="rental-tour-step hidden" data-target="#btnCreateRental" data-target-readonly="1" data-auto-advance="rent-created" data-tutorial-form="rentalForm">
    <h3 class="text-lg font-semibold text-gray-900 mb-2">5. Create the rental</h3>
    <p class="text-sm text-gray-600 leading-relaxed">
        <strong>Click to create the rental.</strong> It's created right away, unless the item is on cool-down (then it needs Management approval).
    </p>
</div>
