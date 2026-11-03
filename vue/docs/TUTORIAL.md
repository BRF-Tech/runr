# RunR Tutorial / Tour System

The console tour: an interactive walkthrough that shows new users the interface step by step.

## Technology

- **Library:** [driver.js](https://driverjs.com/) v1.4.0
- **Pattern:** composable-based, i18n-aware, reacts to locale changes
- **Theme:** custom popover that works in dark mode (`.runr-tour-popover`)

## File map

```
vue/src/
├── composables/
│   ├── useTutorial.js          # buildDriver(), createTutorial(), 6 exported hooks
│   └── tutorialSteps.js        # Step definitions (6 flow functions)
├── stores/
│   └── tutorial.js             # Pinia: tutorialActive, shouldAutoStart, restartCounter
├── locales/
│   ├── en/tutorial.js          # English strings
│   └── tr/tutorial.js          # Turkish strings
└── assets/
    └── main.css (lines 129-254) # driver.js theme overrides
```

## Tour flows

### 1. Projects tour (10 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | *(none — overlay)* | — | Welcome message |
| 2 | `search-input` | ProjectsView | Search box |
| 3 | `filter-tabs` | ProjectsView | Type filter tabs (All/XaaS/FaaS/CaaS) |
| 4 | `filter-dropdowns` | ProjectsView | Advanced filters (runtime, scaling, region) |
| 5 | `new-project-btn` | ProjectsView | New project button |
| 6 | `resource-bars` | ProjectsView | CPU/memory resource bars |
| 7 | `project-card` | ProjectsView | First project card (idx===0) |
| 8 | `notifications-btn` | AppHeader | Notification bell |
| 9 | `main-nav` | MainNav (AppHeader) | The main menu: Projects and the Build & Run, Data & Integrations, Observability and Administration menus |
| 10 | `user-menu-btn` | UserMenu (AppHeader) | The user menu: account settings, theme, language, tutorial, sign-out |

**Trigger:** automatically on the first sign-in (`tutorial_completed_at === null`), later with **Restart tutorial** in the user menu.
**On completion:** `POST /api/tutorial/complete` stores the timestamp in the database.

### 2. Create tour (8 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `create-name` | CreateProjectModal (step 1) | Project name |
| 2 | `create-types` | CreateProjectModal (step 2) | Service type selection |
| 3 | `create-lang` | CreateStep3 | Runtime/language picker |
| 4 | `create-container` | CreateStep3 | CaaS container config (conditional) |
| 5 | `create-trigger` | CreateStep3 | Trigger (HTTP/CRON/Queue) |
| 6 | `create-resources` | CreateStep4 | CPU/memory/timeout |
| 7 | `create-scale` | CreateStep4 | Scaling settings |
| 8 | `create-region` | CreateProjectModal (step 1) | Region picker |

### 3. Settings tour (6 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `settings-endpoint` | SettingsView | Base domain field |
| 2 | `settings-resources` | SettingsView | Total resource bars |
| 3 | `settings-demo` | DemoManager | Demo data management |
| 4 | `settings-regions` | RegionsList | Region list |
| 5 | `settings-add-region` | RegionsList | Add region button |
| 6 | `settings-save` | SettingsView | Save button |

### 4. Detail tour (9 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `project-header` | ProjectHeader | Project name, type and runtime badges |
| 2 | `action-buttons` | ProjectHeader | Edit/Clone/Delete/Deploy buttons |
| 3 | `version-list` | VersionList | Version history sidebar |
| 4 | `editor-area` | EditorPanel | Monaco editor area |
| 5 | `commit-bar` | EditorPanel | Commit message + Save |
| 6 | `deployments-tab` | ProjectHeader | Deployments tab |
| 7 | `metrics-tab` | ProjectHeader | Metrics history tab |
| 8 | `deployment-log-btn` | DeploymentTable | Log button (first row) |
| 9 | `deployment-delete-btn` | DeploymentTable | Delete button (first row) |

### 5. Edit tour (4 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `edit-name` | EditProjectModal step 1 | Name-is-locked notice |
| 2 | `edit-type` | EditProjectModal step 2 | Type is locked (read-only) |
| 3 | `edit-trigger` | EditProjectModal step 3 | Changing the trigger |
| 4 | `edit-resources` | EditProjectModal step 4 | Updating resources + scaling |

### 6. Region wizard tour (5 steps)
| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `region-general` | RegionDetailModal step 1 | Name + kubeconfig |
| 2 | `region-workers` | RegionDetailModal step 2 | Node selection + registry |
| 3 | `region-operator` | RegionDetailModal step 3 | Knative Operator installation |
| 4 | `region-serving` | RegionDetailModal step 4 | Knative Serving version selection |
| 5 | `region-redis` | RegionDetailModal step 5 | Redis connection |

### 7. Branding tour (`brandingSteps`)
Administration → Branding and → Organization Branding (white-label `BrandingForm`); the settings
tabs `settings-branding-tab` / `settings-org-branding-tab` start it on a tutorial restart
(`tutorial: true`), after the tab steps of their section.

| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `branding-presets` | BrandingForm (Colours) | Colour presets: RunR, Ocean (blue brand, navy dark theme) |
| 2 | `branding-brand-colors` | BrandingForm (Colours) | Base colour, accent, shades 300–900 |
| 3 | `branding-dark-tones` | ThemeToneEditor (dark) | The dark theme's base colour, tones 50–950, preview and contrast |
| 4 | `branding-light-tones` | ThemeToneEditor (light) | The same for the light theme |
| 5 | `branding-default-theme` | BrandingForm (Themes) | The theme of users who never picked one |
| 6 | `branding-preview` | BrandingForm | Login page preview |
| 7 | `branding-save` | BrandingForm | Save |

### 8. Bot protection tour (`botProtectionSteps`)
Administration → Access & security → Bot protection (white-label `BotProtectionSettings`); the
settings tab `settings-bot-protection-tab` (a step of the section's tab tour) starts it on a
tutorial restart (`tutorial: true`), after the tab steps of its section. Inside an organization
the page has its own provider and keys only: steps 3–5 drop out.

| # | data-tour | Location | Description |
|---|-----------|----------|-------------|
| 1 | `bot-protection-provider` | BotProtectionSettings | Provider (Turnstile, reCAPTCHA, hCaptcha, off; an organization: its own or the platform's) |
| 2 | `bot-protection-keys` | BotProtectionSettings | Site key and the write-only secret |
| 3 | `bot-protection-forms` | BotProtectionSettings (platform) | Guarded forms: sign-in, demo sign-in, sign-up, public forms |
| 4 | `bot-protection-fallback` | BotProtectionSettings (platform) | The browser check while the provider is down, the timeout |
| 5 | `bot-protection-status` | BotProtectionSettings (platform) | Whether the fallback is active, how many checks were handed out |
| 6 | `bot-protection-save` | BotProtectionSettings | Save, Reset to the installation defaults |

## Adding a step

### 1. Add a marker to the template
```html
<div data-tour="my-element">...</div>
```

### 2. Add the step definition (`tutorialSteps.js`)
```js
{ element: '[data-tour="my-element"]', popover: { title: t('tutorial.section.myElement'), description: t('tutorial.section.myElementDesc') } },
```

### 3. Add the i18n keys
**`locales/en/tutorial.js`** and **`locales/tr/tutorial.js`** — a title and a description in both.

### 4. Conditional steps
`filterSteps()` runs automatically for elements that are not always in the DOM:
a step whose element is missing is skipped.

## Technical details

### buildDriver() settings
- `showProgress: false` — the tour uses its own progress counter
- `overlayColor: 'rgba(0,0,0,0.75)'`
- `stagePadding: 8`, `stageRadius: 12`
- `popoverClass: 'runr-tour-popover'`

### Locale change
When the language changes during a tour, the current index is saved, the tour is destroyed and
rebuilt on nextTick, and it continues from the same index.

### Backend
- **Endpoint:** `POST /api/tutorial/complete`
- **Model:** `User.tutorial_completed_at` (nullable datetime)
- **Auto-start:** `shouldAutoStart = user.tutorial_completed_at === null`
