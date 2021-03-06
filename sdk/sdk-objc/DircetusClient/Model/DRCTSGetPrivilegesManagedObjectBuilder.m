

#import "DRCTSGetPrivilegesManagedObjectBuilder.h"

/**
* NOTE: This class is auto generated by the swagger code generator program.
* https://github.com/swagger-api/swagger-codegen
* Do not edit the class manually.
*/

@implementation DRCTSGetPrivilegesManagedObjectBuilder

-(instancetype)init {
    self = [super init];
    if (self != nil) {
       _metaBuilder = [[DRCTSGetTablesMetaManagedObjectBuilder alloc] init];
       _dataBuilder = [[DRCTSGetPrivilegesDataManagedObjectBuilder alloc] init];
    }
    return self;
}

-(DRCTSGetPrivilegesManagedObject*)createNewDRCTSGetPrivilegesManagedObjectInContext:(NSManagedObjectContext*)context {
    DRCTSGetPrivilegesManagedObject *managedObject = [NSEntityDescription insertNewObjectForEntityForName:NSStringFromClass([DRCTSGetPrivilegesManagedObject class]) inManagedObjectContext:context];
    return managedObject;
}

-(DRCTSGetPrivilegesManagedObject*)DRCTSGetPrivilegesManagedObjectFromDRCTSGetPrivileges:(DRCTSGetPrivileges*)object context:(NSManagedObjectContext*)context {
    DRCTSGetPrivilegesManagedObject* newDRCTSGetPrivileges = [self createNewDRCTSGetPrivilegesManagedObjectInContext:context];
    [self updateDRCTSGetPrivilegesManagedObject:newDRCTSGetPrivileges withDRCTSGetPrivileges:object];
    return newDRCTSGetPrivileges;
}

-(void)updateDRCTSGetPrivilegesManagedObject:(DRCTSGetPrivilegesManagedObject*)managedObject withDRCTSGetPrivileges:(DRCTSGetPrivileges*)object {
    if(!managedObject || !object) {
        return;
    }
    NSManagedObjectContext* context = managedObject.managedObjectContext;

    if(!managedObject.meta) {
        managedObject.meta = [self.metaBuilder DRCTSGetTablesMetaManagedObjectFromDRCTSGetTablesMeta:object.meta context:managedObject.managedObjectContext];
    } else {
        [self.metaBuilder updateDRCTSGetTablesMetaManagedObject:managedObject.meta withDRCTSGetTablesMeta:object.meta];
    }
    if(managedObject.data) {
        for (id object in managedObject.data) {
            [context deleteObject:object];
        }
    }
    if(object.data) {
        NSMutableOrderedSet * convertedObjs = [NSMutableOrderedSet orderedSet];
        for (id innerObject in object.data) {
            id convertedObj = [self.dataBuilder DRCTSGetPrivilegesDataManagedObjectFromDRCTSGetPrivilegesData:innerObject context:managedObject.managedObjectContext];
            [convertedObjs addObject:convertedObj];
        }
        managedObject.data = convertedObjs;
    }

}

-(DRCTSGetPrivileges*)DRCTSGetPrivilegesFromDRCTSGetPrivilegesManagedObject:(DRCTSGetPrivilegesManagedObject*)obj {
    if(!obj) {
        return nil;
    }
    DRCTSGetPrivileges* newDRCTSGetPrivileges = [[DRCTSGetPrivileges alloc] init];
    [self updateDRCTSGetPrivileges:newDRCTSGetPrivileges withDRCTSGetPrivilegesManagedObject:obj];
    return newDRCTSGetPrivileges;
}

-(void)updateDRCTSGetPrivileges:(DRCTSGetPrivileges*)newDRCTSGetPrivileges withDRCTSGetPrivilegesManagedObject:(DRCTSGetPrivilegesManagedObject*)obj {
    newDRCTSGetPrivileges.meta = [self.metaBuilder DRCTSGetTablesMetaFromDRCTSGetTablesMetaManagedObject:obj.meta];
    if(obj.data != nil) {
        NSMutableArray* convertedObjs = [NSMutableArray array];
        for (id innerObject in obj.data) {
            id convertedObj = [self.dataBuilder DRCTSGetPrivilegesDataFromDRCTSGetPrivilegesDataManagedObject:innerObject];
            [convertedObjs addObject:convertedObj];
        }
        newDRCTSGetPrivileges.data = (NSArray<DRCTSGetPrivilegesData>*)convertedObjs;
    }
    
}

@end
