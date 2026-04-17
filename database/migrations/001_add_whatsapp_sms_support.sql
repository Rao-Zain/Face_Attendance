-- Migration: Add WhatsApp/SMS support to notifications table
-- Run this against your existing database to update the sent_via column

ALTER TABLE notifications
    MODIFY COLUMN sent_via ENUM('email', 'sms', 'whatsapp', 'email+whatsapp', 'email+sms', 'system') NOT NULL DEFAULT 'system';
