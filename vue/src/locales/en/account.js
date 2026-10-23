export default {
  account: {
    title:          'Account settings',
    backToProjects: '← Projects',

    // Tabs
    tabAccount:       'Account',
    tabSupport:       'Support',

    // Stats
    statAccount:    'Account Status',
    statActive:     'Active',
    statSessions:   'Active Sessions',
    stat2FA:        'Two-Factor Auth',

    // Profile
    profileTitle:   'Profile Information',
    avatar:         'Avatar',
    uploadAvatar:   'Upload',
    removeAvatar:   'Remove',
    avatarHint:     'JPG, PNG or WebP. Max 2 MB.',
    name:           'Full Name',
    username:       'Username',
    email:          'Email',
    bio:            'Bio',
    bioPlaceholder: 'A short bio about yourself...',
    phone:          'Phone',
    phonePlaceholder: '+90 555 000 00 00',
    profileSaved:   'Profile updated.',

    // Security
    securityTitle:    'Security',

    // Password
    passwordTitle:    'Change Password',
    currentPassword:  'Current Password',
    newPassword:      'New Password',
    confirmPassword:  'Confirm New Password',
    changePassword:   'Change Password',
    passwordChanged:  'Password changed.',

    // 2FA
    twoFactorTitle:     'Two-Factor Authentication',
    twoFactorDesc:      'Add an extra layer of security to your account using a TOTP authenticator app.',
    twoFactorEnabled:   'Enabled',
    twoFactorDisabled:  'Disabled',
    enable2FA:          'Enable',
    disable2FA:         'Disable',
    setup2FATitle:      'Set Up Two-Factor Authentication',
    setup2FAStep1:      '1. Scan this QR code with your authenticator app',
    setup2FAStep2:      '2. Enter the 6-digit code from your app',
    setup2FAManual:     'Or enter this key manually (click to copy):',
    setup2FACopied:     'Copied! Use within 60 seconds.',
    verifyCode:         'Verification Code',
    verifyAndEnable:    'Verify & Enable',
    recoveryCodes:      'Recovery Codes',
    recoveryCodesDesc:  'Store these codes in a safe place. Each code can only be used once.',
    downloadRecoveryCodes: 'Download Recovery Codes (PDF)',
    regenerateCodes:    'Regenerate Codes',
    codesRegenerated:   'Recovery codes regenerated.',
    disable2FAConfirm:  'Enter your password to disable two-factor authentication.',
    twoFactorDisabledMsg: 'Two-factor authentication disabled.',

    // Sessions
    sessionsTitle:      'Active Sessions',
    sessionsDesc:       'Manage your active sessions. You can revoke sessions you no longer use.',
    currentSession:     'Current',
    revokeSession:      'Revoke',
    revokeAll:          'Revoke All Other Sessions',
    sessionRevoked:     'Session revoked.',
    allSessionsRevoked: 'All other sessions revoked.',
    revokeConfirm:      'Are you sure you want to revoke this session?',
    revokeAllConfirm:   'Are you sure you want to revoke all other sessions?',
    lastActive:         'Last active',
    device:             'Device',

    // Danger zone
    dangerTitle:        'Danger Zone',
    disableAccount:     'Disable Account',
    disableDesc:        'Disable your account. You will be logged out of all other sessions.',
    disableConfirm:     'Are you sure you want to disable your account?',
    deleteAccount:      'Delete Account',
    deleteDesc:         'Permanently delete your account and all associated data. This action cannot be undone.',
    deleteConfirm:      'Enter your password to confirm account deletion.',
    deletePassword:     'Password',
    accountDisabled:    'Account disabled.',
    accountDeleted:     'Account deleted.',
  },
}
