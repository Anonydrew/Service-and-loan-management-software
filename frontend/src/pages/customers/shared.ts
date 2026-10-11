/** Query keys for everything shown about one customer, so one action refreshes them all. */
export const customerKeys = (id: number | string) => [
  ['customer', String(id)],
  ['customer-docs', String(id)],
  ['customer-consents', String(id)],
  ['customer-biometrics', String(id)],
  ['customer-onboarding', String(id)],
  ['customer-activity', String(id)],
  ['/api/customers'],
];
