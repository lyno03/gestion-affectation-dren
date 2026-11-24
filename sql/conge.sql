-- Demandes de congé du personnel (RH, chefs de CISCO et d'établissement)
-- À exécuter dans phpMyAdmin : base dren_db > onglet SQL
CREATE TABLE IF NOT EXISTS demande_conge (
    id_conge      INT AUTO_INCREMENT PRIMARY KEY,
    numero        VARCHAR(20)  NOT NULL UNIQUE,
    id_user       INT          NOT NULL,
    type_conge    VARCHAR(30)  NOT NULL,
    date_debut    DATE         NOT NULL,
    date_fin      DATE         NOT NULL,
    nb_jours      INT          NOT NULL,
    commentaire   VARCHAR(255) NULL,
    statut        ENUM('EN_ATTENTE','ACCEPTEE','REFUSEE','ANNULEE') NOT NULL DEFAULT 'EN_ATTENTE',
    date_demande  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_decision DATETIME     NULL,
    id_decideur   INT          NULL,
    motif_refus   VARCHAR(255) NULL,
    INDEX idx_conge_user (id_user, statut),
    INDEX idx_conge_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
