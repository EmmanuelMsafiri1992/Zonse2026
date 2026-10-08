# Zonseo — Master Module Catalogue

One subscription platform. Every profession picks the modules that fit them.

Legend
- `✔` already in `got.txt`
- `➕` added in this pass (was missing)
- Your six original seeds (accounting, invoicing, doctor management, appointments, online ticketing, task management) are marked `★`

Every module below is designed as an installable **module** inside one codebase. A workspace (company, clinic, school, church, farm, individual) switches modules on and off. Plans bundle modules.

---

## 0. Platform Core (every workspace gets these)

| # | Module | Status |
|---|--------|--------|
| 0.1 | Workspaces (multi-company), branches / locations | ✔ |
| 0.2 | Users, teams, roles & granular permissions | ✔ |
| 0.3 | Subscription plans, trials, upgrades, invoices for the platform itself | ✔ |
| 0.4 | Module marketplace (turn modules on/off, per-module pricing) | ➕ |
| 0.5 | Onboarding wizard: "What do you do?" → recommended bundle | ➕ |
| 0.6 | Dashboards & report builder (drag-and-drop widgets) | ✔ |
| 0.7 | Notifications: email, SMS, WhatsApp, push, in-app | ✔ |
| 0.8 | Unified inbox (email, SMS, WhatsApp, Facebook/Instagram DMs) | ➕ |
| 0.9 | Multi-currency, multi-language, RTL, time zones | ✔ |
| 0.10 | API, webhooks & integrations marketplace (Zapier-style) | ✔ |
| 0.11 | Audit logs, backups, data export (GDPR/POPIA) | ✔ |
| 0.12 | Workflow automation builder (triggers → conditions → actions) | ✔ |
| 0.13 | Custom fields & form builder on every record | ➕ |
| 0.14 | Approval engine (multi-level approvals on any document) | ✔ (forms & approvals) |
| 0.15 | Document templates & PDF designer (invoice, receipt, letter, certificate) | ➕ |
| 0.16 | E-signature | ✔ |
| 0.17 | Document OCR & AI data capture (receipts, IDs, invoices) | ✔ |
| 0.18 | AI assistant (ask questions about your data, draft emails, summarise) | ✔ |
| 0.19 | Mobile app (PWA + native wrapper), offline mode | ✔ |
| 0.20 | USSD / feature-phone access for field users | ✔ |
| 0.21 | Two-factor auth, SSO (Google, Microsoft), passkeys | ➕ |
| 0.22 | White-label & reseller program (custom domain, logo, colours) | ➕ |
| 0.23 | Portals: customer, vendor, employee, patient, student/parent, tenant, member, donor | ✔ partial → ➕ unified |
| 0.24 | Public booking / ordering / payment pages (link-in-bio style) | ➕ |
| 0.25 | Hardware: receipt printers, barcode scanners, weighing scales, card readers, QR | ➕ |
| 0.26 | Tax-authority e-invoicing / fiscalisation (ZIMRA FDMS, KRA eTIMS, ZRA, SARS, ZATCA, FIRS…) | ➕ |
| 0.27 | Data import / migration wizard (CSV, Excel, QuickBooks, Sage, Excel templates) | ➕ |
| 0.28 | Calendar & scheduling engine shared by all booking modules | ✔ |
| 0.29 | File storage & document management | ✔ |
| 0.30 | Help centre, in-app tours, release notes | ➕ |

---

## 1. Finance & Accounting

| # | Module | Status |
|---|--------|--------|
| 1.1 | ★ Accounting core: chart of accounts, general ledger, journals, trial balance, P&L, balance sheet, cash flow | ➕ (was implied) |
| 1.2 | ★ Invoicing, credit notes, receipts, recurring invoices | ➕ (was implied) |
| 1.3 | Quotations & estimates → convert to invoice | ✔ |
| 1.4 | Bank accounts, bank feeds / statement import, reconciliation | ➕ |
| 1.5 | Payroll & payslips, statutory deductions per country (PAYE, NSSA, NHIF, UIF…) | ✔ |
| 1.6 | Expense tracking & receipt capture, expense claims & reimbursements | ✔ |
| 1.7 | Petty cash & cash books | ➕ |
| 1.8 | Budgeting, forecasting, variance analysis | ✔ |
| 1.9 | Tax & VAT management, tax returns, withholding tax | ✔ |
| 1.10 | Point of Sale (retail, restaurant, pharmacy modes) | ✔ |
| 1.11 | Purchase orders, procurement, requisitions, tenders/RFQ | ✔ |
| 1.12 | Accounts receivable: statements, dunning, debt collection, credit control | ✔ |
| 1.13 | Accounts payable: bills, supplier payments, payment runs, cheque printing | ➕ |
| 1.14 | Fixed assets register & depreciation | ✔ |
| 1.15 | Loan & microfinance management (loan products, schedules, arrears, collateral) | ✔ |
| 1.16 | Savings groups / village banking / stokvels / chamas / SACCO | ✔ |
| 1.17 | Subscription & recurring billing (for your customers) | ✔ |
| 1.18 | Payment gateways & digital wallet (Stripe, PayPal, Paystack, Flutterwave, Paynow, M-Pesa, EcoCash, MTN MoMo, Airtel Money) | ✔ |
| 1.19 | Cost centres, projects & job costing, project accounting | ➕ |
| 1.20 | Multi-entity consolidation, inter-company transactions | ➕ |
| 1.21 | Inventory valuation (FIFO / weighted average / landed cost) | ➕ |
| 1.22 | Treasury: fixed deposits, investments, forex positions | ➕ |
| 1.23 | Hire purchase, lay-by / layaway, instalment plans | ➕ |
| 1.24 | Credit scoring, KYC / AML checks | ➕ |
| 1.25 | Accounting-firm practice management (clients, tax deadlines, filings, engagement letters) | ➕ |
| 1.26 | Audit working papers | ➕ |
| 1.27 | Insurance management: policies, premiums, claims, brokers, commissions, reinsurance | ➕ |
| 1.28 | Insurance premium financing | ➕ |
| 1.29 | Forex bureau / money transfer agent | ➕ |
| 1.30 | Mobile-money agent float management | ➕ |
| 1.31 | Pawnshop & collateral lending | ➕ |
| 1.32 | Investment clubs & crowdfunding of businesses | ➕ |
| 1.33 | Personal finance & household budgeting | ➕ |

---

## 2. Sales, CRM & Customer Service

| # | Module | Status |
|---|--------|--------|
| 2.1 | CRM: leads, contacts, companies, deals pipeline, activities | ✔ |
| 2.2 | ★ Helpdesk / support ticketing with SLAs, canned replies, knowledge base | ✔ |
| 2.3 | Live chat & chatbot (web, WhatsApp, Messenger) | ✔ |
| 2.4 | Loyalty points, rewards, stamp cards | ✔ |
| 2.5 | Customer feedback, surveys, NPS, reviews & reputation | ✔ |
| 2.6 | Contracts & e-signature | ✔ |
| 2.7 | Affiliate & referral management | ✔ |
| 2.8 | Proposals & sales documents builder | ➕ |
| 2.9 | Sales commissions & targets | ➕ |
| 2.10 | Call centre: dialer, call logs, IVR, call recording | ➕ |
| 2.11 | Field sales / van sales & route planning | ➕ |
| 2.12 | Customer portal (invoices, tickets, documents, bookings) | ✔ |
| 2.13 | Gift cards, vouchers, store credit | ✔ |
| 2.14 | Warranty & returns (RMA) management | ➕ |
| 2.15 | Service contracts & AMC (annual maintenance contracts) | ➕ |

---

## 3. Marketing & Content

| # | Module | Status |
|---|--------|--------|
| 3.1 | Email marketing, newsletters, automation sequences | ✔ |
| 3.2 | SMS & WhatsApp bulk messaging, templates, campaigns | ✔ |
| 3.3 | Social media scheduling & inbox | ✔ |
| 3.4 | Landing page & website builder, blog/CMS | ✔ |
| 3.5 | Event marketing & promo codes | ✔ |
| 3.6 | SEO & analytics dashboard, link shortener, QR codes, link-in-bio | ✔ + ➕ |
| 3.7 | Ads manager (Meta/Google reporting) | ➕ |
| 3.8 | Digital signage / TV display (menus, queues, adverts) | ➕ |
| 3.9 | Membership site & paywall, digital downloads, e-book store | ➕ |
| 3.10 | Podcast / media hosting | ➕ |
| 3.11 | Advertising agency job bags & media booking | ➕ |
| 3.12 | Brand asset library | ➕ |

---

## 4. Operations, Inventory & Supply Chain

| # | Module | Status |
|---|--------|--------|
| 4.1 | Inventory & stock control, barcodes, batches/lots, expiry, serials | ✔ |
| 4.2 | Warehouse management (bins, picking, packing, transfers) | ✔ |
| 4.3 | Supplier & vendor management, vendor portal | ✔ |
| 4.4 | Manufacturing: BOM, work orders, production planning, recipes/formulas, QC | ✔ + ➕ |
| 4.5 | Order management, back-orders, drop-shipping | ✔ |
| 4.6 | Delivery, courier, parcel tracking, proof of delivery | ✔ |
| 4.7 | Fleet & vehicle management, fuel, trip sheets, driver management | ✔ |
| 4.8 | Equipment / machine maintenance (CMMS), preventive schedules | ✔ |
| 4.9 | Freight forwarding, customs clearing, shipments, containers | ➕ |
| 4.10 | Wholesale distribution & consignment stock | ➕ |
| 4.11 | Equipment & tool hire (tents, chairs, machinery, scaffolding) | ➕ |
| 4.12 | Weighbridge & bulk dispatch (quarry, grain, mining) | ➕ |
| 4.13 | Quality management (ISO, NCRs, CAPA) | ➕ |
| 4.14 | Asset tracking with QR/RFID, check-in/out | ➕ |
| 4.15 | Fuel station management (pumps, shifts, tank dips) | ➕ |
| 4.16 | LPG / gas cylinder distribution | ➕ |
| 4.17 | Moving / removals company | ➕ |
| 4.18 | Towing & roadside assistance dispatch | ➕ |

---

## 5. HR & People

| # | Module | Status |
|---|--------|--------|
| 5.1 | Employee records (HRIS), documents, contracts | ✔ |
| 5.2 | Recruitment & applicant tracking, job board, interview scheduling | ✔ |
| 5.3 | Leave management | ✔ |
| 5.4 | Attendance, biometric/geo clock-in, timesheets | ✔ |
| 5.5 | Shift & roster scheduling | ✔ |
| 5.6 | Performance reviews, OKRs, 360 feedback | ✔ |
| 5.7 | Training, onboarding, certifications tracking | ✔ |
| 5.8 | Employee self-service portal | ✔ |
| 5.9 | Disciplinary & grievance case management | ➕ |
| 5.10 | Staffing / outsourcing agency (placements, client billing, worker payouts) | ➕ |
| 5.11 | Overtime, allowances, loans & advances to staff | ➕ |
| 5.12 | Exit / offboarding & clearance | ➕ |
| 5.13 | Org chart & succession planning | ➕ |
| 5.14 | Health & safety: incidents, PPE, toolbox talks | ✔ |

---

## 6. Projects, Tasks & Collaboration

| # | Module | Status |
|---|--------|--------|
| 6.1 | ★ Task management (lists, boards, priorities, recurring tasks) | ✔ |
| 6.2 | Project management: Gantt, Kanban, milestones, dependencies, resource load | ✔ |
| 6.3 | Time tracking & billable hours → invoice | ✔ |
| 6.4 | Document management & file sharing with versioning | ✔ |
| 6.5 | Team chat & internal messaging, channels | ✔ |
| 6.6 | Video meetings | ✔ |
| 6.7 | Shared calendars & room/resource booking | ✔ |
| 6.8 | Knowledge base / wiki | ✔ |
| 6.9 | Forms & approvals workflow | ✔ |
| 6.10 | Notes & whiteboards | ✔ |
| 6.11 | Goals & OKR tracking | ➕ |
| 6.12 | Issue / bug tracking & product roadmap | ➕ |
| 6.13 | Meeting minutes & action items | ➕ |
| 6.14 | Client portal for agencies/consultants | ✔ |
| 6.15 | Freelancer workspace (proposals, contracts, time, invoices) | ➕ |

---

## 7. Healthcare

| # | Module | Status |
|---|--------|--------|
| 7.1 | ★ Doctor / clinic management (patients, visits, prescriptions, billing) | ✔ |
| 7.2 | ★ Appointment booking, reminders, waitlists | ✔ |
| 7.3 | Electronic medical records (EMR / EHR), ICD-10 coding | ✔ |
| 7.4 | Pharmacy management, dispensing, controlled drugs | ✔ |
| 7.5 | Laboratory management (LIS), sample tracking, result portals | ✔ |
| 7.6 | Hospital: admissions, beds & wards, theatre, nursing notes, discharge | ✔ |
| 7.7 | Telemedicine (video consults, e-prescriptions) | ✔ |
| 7.8 | Medical aid / insurance claims & pre-authorisation | ✔ |
| 7.9 | Dental, optometry, physiotherapy, dermatology practice modules | ✔ |
| 7.10 | Veterinary clinic management | ✔ |
| 7.11 | Patient queue / token management & waiting-room display | ➕ |
| 7.12 | Radiology & diagnostic imaging (RIS), DICOM links | ➕ |
| 7.13 | Blood bank | ➕ |
| 7.14 | Ambulance & emergency dispatch | ➕ |
| 7.15 | Mental health / counselling & therapy practice | ➕ |
| 7.16 | Nutrition & dietitian plans | ➕ |
| 7.17 | Rehabilitation & occupational therapy | ➕ |
| 7.18 | Home care / elderly care / hospice visits & carers | ➕ |
| 7.19 | Maternity & antenatal care | ➕ |
| 7.20 | Immunisation registers & community health workers | ➕ |
| 7.21 | Medical supplies distribution | ➕ |
| 7.22 | Patient portal | ✔ |
| 7.23 | Medical billing & coding for insurers | ➕ |

---

## 8. Education

| # | Module | Status |
|---|--------|--------|
| 8.1 | School management: students, classes, fees, admissions | ✔ |
| 8.2 | Learning management system (LMS) & course marketplace | ✔ |
| 8.3 | Exams, grading, report cards, question banks, online exams | ✔ |
| 8.4 | Timetabling | ✔ |
| 8.5 | Library management | ✔ |
| 8.6 | Tutoring & lesson booking | ✔ |
| 8.7 | Parent portal, student portal | ✔ |
| 8.8 | Certificates & verification | ✔ |
| 8.9 | University / college: faculties, semesters, registration, transcripts, alumni | ➕ |
| 8.10 | Hostel / boarding management | ➕ |
| 8.11 | School transport & bus tracking | ➕ |
| 8.12 | Canteen, tuck-shop & cashless cards | ➕ |
| 8.13 | Scholarships, bursaries & student loans | ➕ |
| 8.14 | Driving school (lessons, instructors, test bookings) | ➕ |
| 8.15 | Nursery / daycare / childcare (check-in, meals, naps, parent updates) | ➕ |
| 8.16 | Training centres & vocational colleges (short courses, certifications) | ➕ |
| 8.17 | Discipline & behaviour tracking | ➕ |
| 8.18 | Sports academies & coaching | ➕ |

---

## 9. Hospitality, Food & Travel

| # | Module | Status |
|---|--------|--------|
| 9.1 | Hotel, lodge & guest-house booking, front desk, housekeeping, channel manager | ✔ |
| 9.2 | Restaurant: tables, orders, kitchen display, QR menu, reservations | ✔ |
| 9.3 | Food ordering & delivery, cloud kitchen | ✔ |
| 9.4 | Tour & travel booking, itineraries, tour guides | ✔ |
| 9.5 | Car rental management | ✔ |
| 9.6 | Bus / coach seat booking | ✔ |
| 9.7 | Bar, nightclub & bottle service | ➕ |
| 9.8 | Catering & event food orders | ➕ |
| 9.9 | Conference, banquet & venue hire | ✔ (venue booking) |
| 9.10 | Travel agency: flights, visas, packages, commissions | ➕ |
| 9.11 | Safari / camping / activity bookings | ➕ |
| 9.12 | Bakery & confectionery orders (custom cakes) | ➕ |
| 9.13 | Butchery with scale integration | ➕ |

---

## 10. Real Estate, Property & Facilities

| # | Module | Status |
|---|--------|--------|
| 10.1 | Property listings & agents, commissions | ✔ |
| 10.2 | Rental & tenant management, leases | ✔ |
| 10.3 | Rent collection, arrears, utility recharges | ✔ |
| 10.4 | Maintenance requests & work orders | ✔ |
| 10.5 | Estate / body corporate / HOA management, levies | ✔ |
| 10.6 | Short-stay rental (Airbnb-style) | ✔ |
| 10.7 | Land & plot sales, subdivisions, instalment sales | ➕ |
| 10.8 | Mortgage / home-loan origination | ➕ |
| 10.9 | Property valuation | ➕ |
| 10.10 | Facility management & cleaning schedules | ➕ |
| 10.11 | Co-working space & desk/room booking | ➕ |
| 10.12 | Self-storage units | ➕ |
| 10.13 | Parking management | ➕ |
| 10.14 | Tenant portal | ✔ |

---

## 11. Construction, Engineering & Trades

| # | Module | Status |
|---|--------|--------|
| 11.1 | Construction project management: BOQ, estimating, tenders | ➕ |
| 11.2 | Site diary, daily reports, progress photos | ➕ |
| 11.3 | Subcontractors, progress claims & payment certificates | ➕ |
| 11.4 | Plant & equipment hire and tracking | ➕ |
| 11.5 | Snag lists, inspections, handover | ➕ |
| 11.6 | Drawings & document control | ➕ |
| 11.7 | Architecture / engineering practice management | ➕ |
| 11.8 | Surveying & GIS jobs | ➕ |
| 11.9 | Electrical / plumbing / HVAC contractor job cards | ➕ |
| 11.10 | Solar installer: site surveys, quotes, installations, monitoring | ➕ |

---

## 12. Events, Ticketing & Community

| # | Module | Status |
|---|--------|--------|
| 12.1 | ★ Online ticketing: event tickets, QR check-in, box office, seating plans | ✔ + ➕ |
| 12.2 | Event registration, agendas, speakers, badges | ✔ |
| 12.3 | Venue booking | ✔ |
| 12.4 | Membership & club management, renewals, cards | ✔ |
| 12.5 | Church / ministry: members, cells, tithes, giving, sermons, pledges | ✔ |
| 12.6 | Mosque, temple & other faith organisations | ➕ |
| 12.7 | NGO: donors, grants, projects, beneficiaries, M&E reporting | ✔ + ➕ |
| 12.8 | Fundraising & crowdfunding pages | ✔ |
| 12.9 | Voting & elections (online polls, AGMs) | ✔ |
| 12.10 | Volunteer management | ➕ |
| 12.11 | Cinema / theatre booking | ➕ |
| 12.12 | Sports leagues: fixtures, results, standings, player registration | ➕ |
| 12.13 | Wedding & event planning (vendors, budgets, guest lists) | ➕ |
| 12.14 | Alumni & professional associations (CPD points) | ➕ |
| 12.15 | Social work case management, orphanages, shelters | ➕ |
| 12.16 | Funeral services & funeral policies (undertakers, cemetery plots) | ➕ |

---

## 13. Services & Bookings (appointment-based businesses)

| # | Module | Status |
|---|--------|--------|
| 13.1 | ★ Generic appointment booking (any service, staff, resources) | ✔ |
| 13.2 | Salon, spa, barber booking & commissions | ✔ |
| 13.3 | Gym & fitness memberships, classes, trainers | ✔ |
| 13.4 | Field service: technicians, job cards, dispatch, mobile app | ✔ |
| 13.5 | Cleaning & home services | ✔ |
| 13.6 | Legal practice: cases, matters, court dates, time billing, trust accounts | ✔ |
| 13.7 | Consultancy client portals | ✔ |
| 13.8 | Auto repair garage / workshop: job cards, parts, vehicle history | ➕ |
| 13.9 | Car wash | ➕ |
| 13.10 | Laundry & dry-cleaning | ➕ |
| 13.11 | Tailoring, fashion & measurements, custom orders | ➕ |
| 13.12 | Photography & video studio bookings, galleries, proofing | ➕ |
| 13.13 | Printing, signage & design shop (jobs, artwork approval) | ➕ |
| 13.14 | Pet grooming, boarding & kennels | ➕ |
| 13.15 | Security company: guards, posts, patrols, incidents | ➕ |
| 13.16 | IT services / MSP: helpdesk, assets, contracts, remote support | ➕ |
| 13.17 | Web/hosting agency billing (domains, hosting, renewals) | ➕ |
| 13.18 | Translation, notary & immigration consultants | ➕ |
| 13.19 | Recruitment agency (candidates, clients, placements) | ➕ |
| 13.20 | Music studio, rehearsal rooms & DJ bookings | ➕ |
| 13.21 | Tattoo, piercing & beauty clinics with consent forms | ➕ |

---

## 14. Retail & E-commerce

| # | Module | Status |
|---|--------|--------|
| 14.1 | Online store builder, themes, checkout | ✔ |
| 14.2 | Multi-vendor marketplace, vendor payouts | ✔ |
| 14.3 | Product catalog, variants, bundles | ✔ |
| 14.4 | Shipping, rates & returns | ✔ |
| 14.5 | Gift cards & vouchers | ✔ |
| 14.6 | Supermarket / grocery POS with scales & scanners | ➕ |
| 14.7 | Pharmacy retail POS | ✔ (pharmacy) |
| 14.8 | Hardware & building supplies store | ➕ |
| 14.9 | Liquor store & bottle store | ➕ |
| 14.10 | Mobile phones & electronics (IMEI/serial tracking, repairs) | ➕ |
| 14.11 | Vehicle dealership & spare parts | ➕ |
| 14.12 | Bookshop & stationery | ➕ |
| 14.13 | Second-hand / consignment / thrift | ➕ |
| 14.14 | Classifieds & directory listings | ➕ |
| 14.15 | Job board & freelance marketplace | ➕ |
| 14.16 | Service marketplace (handymen, tutors, pros) | ➕ |
| 14.17 | Social commerce (WhatsApp catalog, Instagram shop sync) | ➕ |

---

## 15. Agriculture & Agribusiness

| # | Module | Status |
|---|--------|--------|
| 15.1 | Farm management: fields, crops, seasons, inputs, yields | ✔ |
| 15.2 | Livestock: herd records, breeding, health, feed | ✔ |
| 15.3 | Cooperative & farmer records, member shares | ✔ |
| 15.4 | Produce sales, aggregation, grading & payouts | ✔ |
| 15.5 | Poultry (flocks, eggs, mortality, feed conversion) | ➕ |
| 15.6 | Dairy & milk collection centres | ➕ |
| 15.7 | Fish farming / aquaculture | ➕ |
| 15.8 | Greenhouse & irrigation scheduling | ➕ |
| 15.9 | Agro-dealer / input supply store | ➕ |
| 15.10 | Contract farming / out-grower schemes & loans in kind | ➕ |
| 15.11 | Tractor & machinery hire | ➕ |
| 15.12 | Grain storage, silos & warehouse receipts | ➕ |
| 15.13 | Extension services & farmer training | ➕ |
| 15.14 | Veterinary & animal health visits (links to 7.10) | ✔ |
| 15.15 | Land leasing & land registry for farms | ➕ |

---

## 16. Transport & Mobility

| # | Module | Status |
|---|--------|--------|
| 16.1 | Taxi / ride-hailing & dispatch | ➕ |
| 16.2 | Minibus / matatu / kombi fleet daily collections ("targets") | ➕ |
| 16.3 | Motorbike (boda-boda) delivery & rider management | ➕ |
| 16.4 | Bus / coach booking (links to 9.6) | ✔ |
| 16.5 | School bus & staff transport tracking | ➕ |
| 16.6 | Haulage & trucking (loads, trips, fuel, tolls) | ➕ |
| 16.7 | Airport shuttles & chauffeur services | ➕ |
| 16.8 | Parking & toll management | ➕ |
| 16.9 | Driving school (links to 8.14) | ➕ |
| 16.10 | GPS tracking integration | ➕ |

---

## 17. Utilities, Energy & Telecom

| # | Module | Status |
|---|--------|--------|
| 17.1 | Utility billing: water, electricity, meter reading, prepaid tokens | ➕ |
| 17.2 | ISP & WiFi hotspot billing (vouchers, RADIUS, Mikrotik) | ➕ |
| 17.3 | Solar & energy systems monitoring (links to 11.10) | ➕ |
| 17.4 | Waste management & refuse collection routes | ➕ |
| 17.5 | Borehole / water delivery & tanker services | ➕ |
| 17.6 | Cable / satellite TV subscriptions | ➕ |
| 17.7 | Telecom airtime & bundle reseller | ➕ |

---

## 18. Government, Public Sector & Compliance

| # | Module | Status |
|---|--------|--------|
| 18.1 | Permit & licence applications, renewals | ✔ |
| 18.2 | Compliance & audit tracking (ISO, regulatory) | ✔ |
| 18.3 | Risk management register | ✔ |
| 18.4 | Health & safety incident reporting | ✔ |
| 18.5 | Visitor management & access control | ✔ |
| 18.6 | E-citizen service desk & complaints | ➕ |
| 18.7 | Local council revenue: market stalls, business licences, rates | ➕ |
| 18.8 | Court case management & cause lists | ➕ |
| 18.9 | Police occurrence book & case tracking | ➕ |
| 18.10 | Land registry & title deeds | ➕ |
| 18.11 | Civil registry: births, deaths, marriages | ➕ |
| 18.12 | E-procurement & tender portal | ➕ |
| 18.13 | Traffic fines & vehicle licensing | ➕ |
| 18.14 | Grants & subsidies management | ➕ |
| 18.15 | Data privacy (GDPR / POPIA) requests & consent | ➕ |
| 18.16 | Environmental monitoring & permits | ➕ |
| 18.17 | Parliament / council: motions, minutes, members | ➕ |
| 18.18 | Prison / correctional records | ➕ |

---

## 19. Mining, Manufacturing & Industrial

| # | Module | Status |
|---|--------|--------|
| 19.1 | Mine & quarry operations: shifts, production, royalties | ➕ |
| 19.2 | Weighbridge (links to 4.12) | ➕ |
| 19.3 | Manufacturing execution (links to 4.4) | ✔ |
| 19.4 | Lab testing & certificates of analysis | ➕ |
| 19.5 | Permit-to-work & safety | ✔ |
| 19.6 | Contractor & site access management | ➕ |

---

## 20. Personal & Lifestyle (individual subscribers)

| # | Module | Status |
|---|--------|--------|
| 20.1 | Personal finance, budgets & savings goals | ➕ |
| 20.2 | Personal tasks, habits & notes (links to 6.1, 6.10) | ✔ |
| 20.3 | Family & household management (chores, shopping, bills) | ➕ |
| 20.4 | Freelancer toolkit (links to 6.15) | ➕ |
| 20.5 | Personal CV / portfolio site (links to 3.4) | ➕ |
| 20.6 | Wedding / event planner (links to 12.13) | ➕ |
| 20.7 | Health & fitness tracker, meal plans | ➕ |
| 20.8 | Landlord with one or two properties (lite 10.2) | ➕ |

---

## 21. Profession → Starter bundle (the onboarding wizard uses this)

| I am a… | Recommended modules |
|---------|--------------------|
| Doctor / clinic | 7.1, 7.2, 7.3, 7.11, 7.8, 1.2, 1.1, 0.23 patient portal |
| Dentist / optometrist / physio | 7.9, 7.2, 7.3, 1.2, 1.1 |
| Pharmacy | 7.4, 1.10, 4.1, 1.1 |
| Hospital | 7.6, 7.1–7.8, 7.12, 7.13, 5.x, 1.x |
| Vet | 7.10, 7.2, 4.1, 1.2 |
| Accountant / bookkeeper | 1.25, 1.1, 1.2, 1.9, 1.5, 6.3, 2.12 |
| Lawyer | 13.6, 6.3, 1.2, 2.6, 6.4 |
| Retail shop / supermarket | 1.10, 14.6, 4.1, 1.2, 2.4 |
| Restaurant / café / bar | 9.2, 9.3, 1.10, 4.1, 5.5 |
| Hotel / lodge | 9.1, 9.2, 1.1, 5.x |
| School | 8.1, 8.3, 8.4, 8.5, 8.7, 8.11, 1.1 |
| University / college | 8.9, 8.2, 8.3, 8.10, 1.1 |
| Online tutor / trainer | 8.2, 8.6, 13.1, 1.2 |
| Church | 12.5, 12.2, 1.1, 3.2, 12.10 |
| NGO | 12.7, 12.10, 1.1, 1.8, 6.2 |
| Salon / spa / barber | 13.2, 13.1, 1.10, 2.4, 3.2 |
| Gym | 13.3, 1.17, 2.4, 13.1 |
| Landlord / estate agent | 10.1, 10.2, 10.3, 10.4, 1.1 |
| Construction / contractor | 11.1–11.6, 6.2, 1.19, 4.11 |
| Electrician / plumber / technician | 13.4, 11.9, 1.3, 1.2 |
| Garage / mechanic | 13.8, 4.1, 1.2, 14.11 |
| Transport / fleet owner | 4.7, 16.2, 16.6, 16.10, 1.1 |
| Bus company | 9.6, 4.7, 1.10 |
| Farmer / cooperative | 15.1–15.4, 15.5, 15.6, 1.1 |
| Microfinance / SACCO | 1.15, 1.16, 1.24, 1.1, 3.2 |
| Insurance broker | 1.27, 2.1, 1.2 |
| Event organiser | 12.1, 12.2, 12.3, 3.5, 1.2 |
| Freelancer / consultant | 6.15, 6.3, 1.2, 2.1, 6.14 |
| Software / IT company | 13.16, 2.2, 6.12, 6.2, 13.17 |
| Manufacturer | 4.4, 4.1, 4.2, 4.13, 1.21, 1.1 |
| Government department / council | 18.x, 0.12, 0.14 |
| Security company | 13.15, 5.5, 5.4, 1.2 |
| Taxi / ride owner | 16.1, 16.2, 4.7 |
| ISP / WiFi business | 17.2, 1.17, 2.2 |
| Individual | 20.x |

---

## 22. Counting

- Modules carried over from `got.txt`: ~120
- Newly added in this pass: ~190
- Total catalogued: ~310 modules across 21 suites

Everything is built on the **same core** (0.x). Most "modules" in sections 9–20 are really *configurations* of a dozen underlying engines (booking, invoicing, inventory, people, cases, documents, payments, forms). See `02-ARCHITECTURE.md` for how that keeps the codebase sane.
