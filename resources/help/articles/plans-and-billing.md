---
title: Plans and billing
category: settings
order: 3
summary: Choose a plan, pay for it in your own currency and see what happens when a plan lapses.
keywords: plan, billing, subscription, pay, upgrade, downgrade, receipt, trial, paynow, ecocash, stripe, card, bank transfer, grace, paused
routes: settings.billing.*, pricing, checkout.*
---

## Choose a plan

Open [Plan & billing](route:settings.billing.index) and pick a plan. Paid plans start with a free trial, so you can set everything up before paying. Switching plans takes effect straight away; apps the new plan does not cover stay on as paid add-ons, and the page tells you before you switch.

Each plan also sets how many people and branches a workspace can have. The Team members and Branches pages show how many of those places you have used, and when they are full the Invite and Add buttons are replaced by a link to upgrade.

## Pay for your plan

Prices are in US dollars, but you can pay in your own currency. The "Pay for your plan" card shows what a month or a year costs in dollars and in every currency we accept, then offers the ways to pay that are open to you:

- **Paynow** for EcoCash, OneMoney, InnBucks, ZimSwitch and local Visa or Mastercard. You are sent to Paynow's page and brought back as soon as it confirms.
- **Card** through Stripe for Visa, Mastercard and other international cards, charged in dollars.
- **Bank transfer or cash** when the account details are shown. Pay, then press "I have sent the money" with your bank reference. Our team confirms it within a working day and your plan starts from that moment.

Paying while a trial or a paid period is still running does not waste anything: the new period is added on to the end of the current one.

## Grace period and paused workspaces

When a trial or a paid period ends without a payment, the workspace keeps working for a short grace period (seven days unless we say otherwise) and a notice at the top of every page says so. After that the apps are paused: the owner is sent to the billing page to pay, and members see a page saying the workspace is paused. Nothing is deleted; paying reopens everything at once. API keys are paused too: every call except `/api/v1/me` answers 402 until the plan is paid. The free plan never pauses.

## Payment history

Every payment, including ones still being confirmed, is listed on the billing page with its reference and amount.

## Only owners and admins

Only the workspace owner and admins can see billing and pay. Members who find the workspace paused should ask the owner to pay.
