export default function movementForm(config) {
    const old = config.old || {};

    return {
        sending: false,
        type: config.type,
        stock: Number(config.stock) || 0,
        tracked: Boolean(config.tracked),
        lots: config.lots || [],
        quantity: old.quantity ?? '',
        counted: old.counted_quantity ?? '',
        reason: old.reason_code ?? config.defaultReason ?? '',
        lotNumber: old.lot_number ?? '',
        expires: old.expires_at ?? '',
        lotId: old.lot_id ?? '',

        get qty() {
            return Number(this.quantity) || 0;
        },

        // Avec le motif « Péremption », seuls les lots expirés sont concernés.
        get removingExpired() {
            return this.reason === 'expiry';
        },

        // Lots utilisables pour une sortie (déjà triés FEFO par le serveur).
        get pool() {
            return this.lots.filter((lot) => lot.expired === this.removingExpired && lot.quantity > 0);
        },

        get selectedLot() {
            return this.lots.find((lot) => String(lot.id) === String(this.lotId)) || null;
        },

        get available() {
            if (this.selectedLot) {
                return this.selectedLot.quantity;
            }

            return this.pool.reduce((sum, lot) => sum + lot.quantity, 0);
        },

        // Répartition prévue de la sortie.
        get plan() {
            if (this.selectedLot) {
                return [{ number: this.selectedLot.number, take: Math.min(this.qty, this.selectedLot.quantity) }];
            }

            let needed = this.qty;
            const lines = [];

            for (const lot of this.pool) {
                if (needed <= 0) {
                    break;
                }

                const take = Math.min(needed, lot.quantity);
                lines.push({ number: lot.number, take });
                needed -= take;
            }

            return lines;
        },

        get shortage() {
            return this.type === 'exit' && this.qty > (this.tracked ? this.available : this.stock);
        },

        get knownLot() {
            const number = this.lotNumber.trim().toUpperCase();

            return this.lots.find((lot) => lot.number === number) || null;
        },

        get theoretical() {
            if (this.tracked) {
                return this.selectedLot ? this.selectedLot.quantity : null;
            }

            return this.stock;
        },

        get difference() {
            if (this.counted === '' || this.theoretical === null) {
                return null;
            }

            return Number(this.counted) - this.theoretical;
        },

        get after() {
            return this.type === 'entry' ? this.stock + this.qty : this.stock - this.qty;
        },

        // Un numéro de lot déjà connu reprend sa date de péremption.
        syncKnownLot() {
            if (this.knownLot) {
                this.expires = this.knownLot.expires_at ?? '';
            }
        },

        lotUnavailable(id) {
            const lot = this.lots.find((candidate) => candidate.id === id);

            return lot ? lot.expired !== this.removingExpired : false;
        },

        resetLotIfOutOfPool() {
            if (this.selectedLot && this.selectedLot.expired !== this.removingExpired) {
                this.lotId = '';
            }
        },

        submit(event) {
            if (! confirm('Valider cette opération ?')) {
                event.preventDefault();

                return;
            }

            this.sending = true;
        },
    };
}