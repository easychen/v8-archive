#import <Foundation/Foundation.h>
#import <CoreData/CoreData.h>

/**
* directus.io
* API for directus.io
*
* OpenAPI spec version: 1.1
* 
*
* NOTE: This class is auto generated by the swagger code generator program.
* https://github.com/swagger-api/swagger-codegen.git
* Do not edit the class manually.
*/


#import "DRCTSGetGroupsDataDataManagedObject.h"
#import "DRCTSGetGroupsDataMetaManagedObject.h"


NS_ASSUME_NONNULL_BEGIN

@interface DRCTSGetGroupsDataManagedObject : NSManagedObject


@property (nullable, nonatomic, retain) DRCTSGetGroupsDataMetaManagedObject* meta;

@property (nullable, nonatomic, retain) NSOrderedSet<DRCTSGetGroupsDataDataManagedObject*>* data;
@end

@interface DRCTSGetGroupsDataManagedObject (GeneratedAccessors)
- (void)addDataObject:(DRCTSGetGroupsDataDataManagedObject *)value;
- (void)removeDataObject:(DRCTSGetGroupsDataDataManagedObject *)value;
- (void)addData:(NSOrderedSet<DRCTSGetGroupsDataDataManagedObject*> *)values;
- (void)removeData:(NSOrderedSet<DRCTSGetGroupsDataDataManagedObject*> *)values;

@end


NS_ASSUME_NONNULL_END
