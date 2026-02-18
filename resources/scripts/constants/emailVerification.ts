export const EMAIL_VERIFICATION_RESTRICTED_ROUTES = ['billing/order', 'tickets', 'donations'] as const;
export const EMAIL_VERIFICATION_FEATURES = ['Products', 'Tickets', 'Donations'] as const;
export const EMAIL_VERIFICATION_ALERT_MESSAGE =
    'Please verify your email address to access this feature. Check your inbox or resend the verification email.';

export const formatRestrictedFeatures = (features: readonly string[] = EMAIL_VERIFICATION_FEATURES) =>
    features.join(', ').replace(/, ([^,]*)$/, ' and $1');
