import './bootstrap';

import Alpine from 'alpinejs';
import movementForm from './movement-form';

window.Alpine = Alpine;

Alpine.data('movementForm', movementForm);

Alpine.start();