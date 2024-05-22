# Experience Builder

## Prerequisites

- Enable the Experience Builder module

## Build steps

1. `npm install` from /modules/experience_builder/ui
2. `npm run build`

## Development mode

1. `npm install` from /modules/experience_builder/ui
2. `npm run dev` - Make sure nothing is running on localhost:5173
3. Enable the Experience Builder Vite Integration module (`xb_vite`)
4. Clear cache (`drush cr` or `/admin/config/development/performance`)
5. Navigate to `/xb` to view app
