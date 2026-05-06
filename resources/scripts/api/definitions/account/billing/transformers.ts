/* eslint-disable camelcase */
import { FractalResponseData } from '@/api/http';
import * as Models from '@definitions/account/billing/models';

export default class Transformers {
    static toOrder = ({ attributes: data }: FractalResponseData): Models.Order => ({
        id: data.id,
        name: data.name,
        user_id: data.user_id,
        description: data.description,
        total: data.total,
        status: data.status,
        product_id: data.product_id,
        type: data.type,
        payment_processor: data.payment_processor || 'stripe',
        payment_intent_id: data.payment_intent_id,
        mollie_payment_id: data.mollie_payment_id,
        paypal_order_id: data.paypal_order_id,
        paypal_capture_id: data.paypal_capture_id,
        paypal_payer_id: data.paypal_payer_id,
        paypal_payer_email: data.paypal_payer_email,
        paypal_status: data.paypal_status,
        paypal_amount: data.paypal_amount,
        paypal_currency: data.paypal_currency,
        paypal_captured_at: data.paypal_captured_at ? new Date(data.paypal_captured_at) : undefined,
        server_id: data.server_id,
        server_uuid: data.server_uuid,
        server_name: data.server_name,
        created_at: new Date(data.created_at),
        updated_at: new Date(data.updated_at),
    });

    static toCategory = ({ attributes: data }: FractalResponseData): Models.Category => ({
        id: data.id,
        name: data.name,
        icon: data.icon,
        description: data.description,
        allowedEggs: data.allowedEggs || [data.eggId],
        allowEggChanges: data.allowEggChanges ?? true,
        allowPlanChanges: data.allowPlanChanges ?? true,
    });

    static toProduct = ({ attributes: data }: FractalResponseData): Models.Product => ({
        id: data.id,
        name: data.name,
        icon: data.icon,
        price: data.price,
        description: data.description,
        eggId: data.egg_id,
        allowedEggs: data.allowed_eggs || [data.egg_id],
        allowEggChanges: data.allow_egg_changes ?? true,
        limits: {
            cpu: data.limits.cpu,
            memory: data.limits.memory,
            disk: data.limits.disk,
            backup: data.limits.backup,
            database: data.limits.database,
            allocation: data.limits.allocation,
            subdomain: data.limits.subdomain ?? null,
        },
    });

    static toNode = ({ attributes: data }: FractalResponseData): Models.Node => ({
        id: data.id,
        name: data.name,
        fqdn: data.fqdn,
        priceMultiplier: data.price_multiplier || 1.0,
        priceMultiplierDescription: data.price_multiplier_description,
    });
}
