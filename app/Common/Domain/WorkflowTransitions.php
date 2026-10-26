<?php

namespace App\Common\Domain;

final class WorkflowTransitions
{
    public static function voyage(): StateMachine
    {
        return new StateMachine([
            'planned' => ['unloading', 'canceled'],
            'unloading' => ['closed', 'canceled'],
            'closed' => [],
            'canceled' => ['planned'],
        ]);
    }

    public static function plan(): StateMachine
    {
        return new StateMachine([
            'draft' => ['approved', 'canceled'],
            'approved' => ['draft', 'in_progress', 'canceled'],
            'in_progress' => ['paused', 'finished', 'canceled'],
            'paused' => ['in_progress', 'canceled'],
            'finished' => [],
            'canceled' => [],
        ]);
    }

    public static function tub(): StateMachine
    {
        return new StateMachine([
            'available' => ['assigned', 'maintenance', 'inactive'],
            'assigned' => ['filling', 'available'],
            'filling' => ['pending_weighing'],
            'pending_weighing' => ['weighed', 'filling'],
            'weighed' => ['received', 'pending_weighing'],
            'received' => ['empty'],
            'empty' => ['available', 'maintenance'],
            'maintenance' => ['available', 'inactive'],
            'inactive' => ['available'],
        ]);
    }

    public static function weighing(): StateMachine
    {
        return new StateMachine([
            'draft' => ['confirmed'],
            'confirmed' => ['pending_inspection', 'received', 'reversed'],
            'pending_inspection' => ['received', 'reversed'],
            'received' => ['reversed'],
            'reversed' => [],
        ]);
    }

    public static function stockUnit(): StateMachine
    {
        return new StateMachine([
            'received' => ['located', 'held', 'rejected'],
            'located' => ['available', 'held'],
            'available' => ['held', 'reserved', 'in_transit', 'dispatched', 'exhausted'],
            'held' => ['available', 'rejected'],
            'reserved' => ['available', 'in_transit', 'dispatched', 'exhausted'],
            'in_transit' => ['available', 'held', 'rejected'],
            'dispatched' => [],
            'rejected' => [],
            'exhausted' => [],
        ]);
    }

    public static function request(): StateMachine
    {
        return new StateMachine([
            'draft' => ['requested', 'canceled'],
            'requested' => ['authorized', 'rejected', 'canceled'],
            'authorized' => ['reserved', 'partially_fulfilled', 'fulfilled', 'canceled'],
            'reserved' => ['partially_fulfilled', 'fulfilled', 'authorized', 'canceled'],
            'partially_fulfilled' => ['fulfilled', 'canceled'],
            'fulfilled' => [],
            'rejected' => [],
            'canceled' => [],
        ]);
    }

    public static function transfer(): StateMachine
    {
        return new StateMachine([
            'requested' => ['authorized', 'canceled'],
            'authorized' => ['prepared', 'canceled'],
            'prepared' => ['shipped', 'canceled'],
            'shipped' => ['in_transit'],
            'in_transit' => ['received', 'discrepancy'],
            'discrepancy' => ['received'],
            'received' => [],
            'canceled' => [],
        ]);
    }
}
