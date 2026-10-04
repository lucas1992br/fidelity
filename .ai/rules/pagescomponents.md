---
paths:
  - 'resources/views/{pages,components}/**/⚡*.blade.php'
---

# Pagescomponents

## Authorize every Livewire entry point
Livewire methods can be called directly, so hiding a button is not authorization. Full-page components call `$this->authorize('<permission>')` in `mount()`, and every public method that changes data (open/confirm/save/delete...) calls `$this->authorize(...)` again, using the spec §10.1 permission or the model policy ability. Cover it with a test that calls the method as a user without the permission and asserts 403.
