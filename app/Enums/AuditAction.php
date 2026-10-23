<?php

namespace App\Enums;

enum AuditAction: string
{
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductActivated = 'product.activated';
    case ProductDeactivated = 'product.deactivated';
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryActivated = 'category.activated';
    case CategoryDeactivated = 'category.deactivated';
    case SupplierCreated = 'supplier.created';
    case SupplierUpdated = 'supplier.updated';
    case SupplierActivated = 'supplier.activated';
    case SupplierDeactivated = 'supplier.deactivated';
    case StockAdjustment = 'stock.adjustment';
    case StockCorrection = 'stock.correction';
    case DataExported = 'data.exported';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserPasswordReset = 'user.password_reset';

    public function label(): string
    {
        return match ($this) {
            self::ProductCreated => 'Produit créé',
            self::ProductUpdated => 'Produit modifié',
            self::ProductActivated => 'Produit réactivé',
            self::ProductDeactivated => 'Produit désactivé',
            self::CategoryCreated => 'Catégorie créée',
            self::CategoryUpdated => 'Catégorie modifiée',
            self::CategoryActivated => 'Catégorie réactivée',
            self::CategoryDeactivated => 'Catégorie désactivée',
            self::SupplierCreated => 'Fournisseur créé',
            self::SupplierUpdated => 'Fournisseur modifié',
            self::SupplierActivated => 'Fournisseur réactivé',
            self::SupplierDeactivated => 'Fournisseur désactivé',
            self::StockAdjustment => 'Ajustement de stock',
            self::StockCorrection => 'Correction de stock',
            self::DataExported => 'Export de données',
            self::UserCreated => 'Compte créé',
            self::UserUpdated => 'Compte modifié',
            self::UserRoleChanged => 'Rôle modifié',
            self::UserActivated => 'Compte réactivé',
            self::UserDeactivated => 'Compte désactivé',
            self::UserPasswordReset => 'Mot de passe réinitialisé',
        };
    }
}