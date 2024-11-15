-- #!mysql
-- #{ auctionhouse
-- #  { init
-- #    { players
CREATE TABLE IF NOT EXISTS auction_house_players(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    gamertag VARCHAR(15) NOT NULL
);
-- #    }
-- #    { auction_house_items
CREATE TABLE IF NOT EXISTS auction_house_items(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item BLOB NOT NULL
);
-- #    }
-- #    { auction_house
CREATE TABLE IF NOT EXISTS auction_house(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    item_id INT NOT NULL,
    player BINARY(16) NOT NULL,
    price FLOAT NOT NULL,
    listing_time INT NOT NULL,
    expiry_time INT NOT NULL,
    FOREIGN KEY (item_id)
        REFERENCES auction_house_items(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (player)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #    { auction_house_bids
CREATE TABLE IF NOT EXISTS auction_house_bids(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    bidder BINARY(16) NULL,
    offer FLOAT NULL,
    placed INT NULL,
    completed INT NULL,
    offered INT NULL,
    FOREIGN KEY (uuid)
        REFERENCES auction_house(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (bidder)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #    { auction_house_logs
CREATE TABLE IF NOT EXISTS auction_house_logs(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    item_id INT NOT NULL,
    buyer BINARY(16) NOT NULL,
    seller BINARY(16) NOT NULL,
    listing_price FLOAT NOT NULL,
    purchase_price FLOAT NOT NULL,
    purchase_time INT NOT NULL,
    FOREIGN KEY (item_id)
        REFERENCES auction_house_items(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (buyer)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (seller)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #    { auction_house_collection_bin
CREATE TABLE IF NOT EXISTS auction_house_collection_bin(
    uuid BINARY(16) NOT NULL,
    item_id INT NOT NULL,
    placement_time INT NOT NULL,
    PRIMARY KEY(uuid, item_id),
    FOREIGN KEY (uuid)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (item_id)
        REFERENCES auction_house_items(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #  }
-- #  { load
-- #    :uuid string
SELECT ah.uuid, ahp.uuid AS player, ahp.gamertag, ah.price, ah.item_id, ah.listing_time, ah.expiry_time,
    (SELECT EXISTS(SELECT 1 FROM auction_house_bids ahb2 WHERE ahb2.uuid=ah.uuid)) AS bidding, ahb.bidder AS bidder_uuid,
    ahbp.gamertag AS bidder_gamertag, ahb.offer AS bidder_offer, ahb.placed AS bidder_placed, ahb.completed AS bidder_completed,
    ahb.offered AS bidder_offered
FROM auction_house ah
INNER JOIN auction_house_players ahp
    ON ahp.uuid=ah.player
LEFT JOIN auction_house_bids ahb
    ON ahb.uuid=ah.uuid
LEFT JOIN auction_house_players ahbp
    ON ahbp.uuid=ahb.bidder
WHERE ah.uuid=:uuid;
-- #  }
-- #  { add_item
-- #    :item string
INSERT INTO auction_house_items(item) VALUES(:item);
-- #  }
-- #  { add
-- #    :uuid string
-- #    :item_id int
-- #    :player string
-- #    :price float
-- #    :listing_time int
-- #    :expiry_time int
INSERT INTO auction_house(uuid, item_id, player, price, listing_time, expiry_time) VALUES(:uuid, :item_id, :player, :price, :listing_time, :expiry_time);
-- #  }
-- #  { bid
-- #    :uuid string
-- #    :bidder string
-- #    :offer float
-- #    :placed int
-- #    :completed int
-- #    :offered int
INSERT OR REPLACE INTO auction_house_bids(uuid, bidder, offer, placed, completed, offered) VALUES(:uuid, :bidder, :offer, :placed, :completed, :offered);
-- #  }
-- #  { log
-- #    :uuid string
-- #    :item_id int
-- #    :buyer string
-- #    :seller string
-- #    :listing_price float
-- #    :purchase_price float
-- #    :purchase_time int
INSERT INTO auction_house_logs(uuid, item_id, buyer, seller, listing_price, purchase_price, purchase_time) VALUES(:uuid, :item_id, :buyer, :seller, :listing_price, :purchase_price, :purchase_time);
-- #  }
-- #  { remove
-- #    :uuid string
DELETE FROM auction_house WHERE uuid=:uuid;
-- #  }
-- #  { remove_bid
-- #    :uuid string
DELETE FROM auction_house_bids WHERE uuid=:uuid;
-- #  }qq
-- #  { item
-- #    :id int
SELECT item FROM auction_house_items WHERE id=:id;
-- #  }
-- #  { count
SELECT COUNT(1) AS c FROM auction_house WHERE expiry_time > unixepoch();
-- #  }
-- #  { list
-- #    :offset int
-- #    :length int
SELECT uuid FROM auction_house WHERE expiry_time > unixepoch() ORDER BY listing_time DESC LIMIT :offset, :length;
-- #  }
-- #  { expiring
-- #    :remaining int
SELECT uuid FROM auction_house WHERE expiry_time < unixepoch() + :remaining ORDER BY expiry_time;
-- #  }
-- #  { unoffered_bids
SELECT uuid FROM auction_house_bids WHERE completed IS NOT NULL AND offered IS NULL ORDER BY completed;
-- #  }
-- #  { add_collection_bin
-- #    :uuid string
-- #    :item_id int
-- #    :placement_time int
INSERT INTO auction_house_collection_bin(uuid, item_id, placement_time) VALUES(:uuid, :item_id, :placement_time);
-- #  }
-- #  { remove_collection_bin
-- #    :uuid string
-- #    :item_id int
DELETE FROM auction_house_collection_bin WHERE uuid=:uuid AND item_id=:item_id;
-- #  }
-- #  { load_collection_bin
-- #    :uuid string
SELECT item_id, placement_time FROM auction_house_collection_bin WHERE uuid=:uuid ORDER BY placement_time DESC LIMIT 45;
-- #  }
-- #  { player
-- #    { init
-- #      :uuid string
-- #      :gamertag string
INSERT OR REPLACE INTO auction_house_players(uuid, gamertag) VALUES(:uuid, :gamertag);
-- #    }
-- #    { listings
-- #      :player string
SELECT uuid FROM auction_house WHERE player=:player AND expiry_time > unixepoch() ORDER BY listing_time DESC LIMIT 45;
-- #    }
-- #    { stats
-- #      :player string
SELECT
    (SELECT COUNT(1) FROM auction_house_collection_bin WHERE uuid=:player) AS binned,
    (SELECT COUNT(1) FROM auction_house WHERE player=:player) AS listings;
-- #    }
-- #  }

-- #}